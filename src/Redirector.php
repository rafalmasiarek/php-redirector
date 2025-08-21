<?php

declare(strict_types=1);

namespace rafalmasiarek\Redirector;

use rafalmasiarek\Redirector\Middleware\MiddlewareInterface;

/**
 * Class UniversalRedirector
 *
 * Highly configurable redirect engine:
 * - Domain and URI redirects (host, path, query, fragment)
 * - Rules with exact/wildcard/regex matching and per-rule HTTP status (301/302/307/308)
 * - UTM appending with flexible merge/override strategy
 * - Hooks for key lifecycle events
 * - Middleware pipeline (PSR-inspired) to extend behavior (e.g. logging, auth, feature flags)
 * - Loop protection and target allowlist
 *
 * @package rafalmasiarek\Redirector
 */
final class Redirector
{
    /** @var array Full configuration array */
    private array $cfg;

    /** @var Context Request context */
    private Context $ctx;

    /**
     * @param array $config Redirector configuration.
     */
    private function __construct(array $config)
    {
        $this->cfg = $this->withDefaults($config);
        $this->ctx = $this->buildContext(); // initial Context built from server globals
    }

    /**
     * Factory.
     *
     * @param array $config
     * @return static
     */
    public static function make(array $config): self
    {
        return new self($config);
    }

    /**
     * Entry point: run redirect flow and emit response.
     *
     * @return void
     */
    public function run(): void
    {
        $ctx = $this->ctx;

        // SKIP paths
        foreach ($this->cfg['skip'] as $skipPath) {
            if ($this->startsWith($ctx->path, $skipPath)) {
                $this->hook('onSkip', $ctx);
                $this->respond(204, 'No redirect (skip matched).');
                return;
            }
        }

        $this->hook('beforeMatch', $ctx);

        $matchedRule = $this->matchRule();
        $this->hook('afterMatch', $ctx, $matchedRule);
        if (!$matchedRule) {
            $this->hook('onNoMatch', $ctx);
            $this->respond(204, 'No redirect (no rules matched).');
            return;
        }

        $this->hook('beforeBuildTarget', $ctx, $matchedRule);
        $target = $this->buildTargetUrl($matchedRule);

        $maybe = $this->hook('afterBuildTarget', $ctx, $matchedRule, $target);
        if (is_string($maybe) && $maybe !== '') {
            // If hook returned a string, use it as the target URL
            $target = $maybe;
        }

        // Merge query/fragment
        $target = $this->applyPreservation($target);

        // Apply UTM
        $target = $this->applyUtms($target, $matchedRule);

        // Force HTTPS if configured
        if ($this->cfg['force_https']) {
            $target = $this->forceHttps($target);
        }

        // Validate target host if allowlist provided
        $this->assertAllowedTarget($target);

        // Loop protection
        if ($this->cfg['loop_protection'] && $this->urlsEqual($ctx->url, $target)) {
            $this->respond(208, 'Already at target (loop protection).');
            return;
        }

        // Decide status
        $status = $matchedRule['status'] ?? $this->cfg['default_status'];

        // Run middleware pipeline and possibly short-circuit
        if (!$this->runMiddlewarePipeline($ctx, $matchedRule, $target, $status)) {
            // Middleware already responded
            return;
        }

        // Dry run?
        if ($this->cfg['dry_run']) {
            $this->hook('onDryRun', $ctx, $matchedRule, $target, $status);
            $this->debugOutput($matchedRule, $target, $status);
            return;
        }

        // Final hook before sending
        $res = $this->hook('beforeSend', $ctx, $matchedRule, $target, $status);
        if ($res === false) {
            // Hook took over response
            return;
        }

        header('Cache-Control: no-store');
        header('Location: ' . $target, true, $status);
        exit;
    }

    /* ===================== Configuration & Context ===================== */

    /**
     * Apply defaults to the configuration array.
     *
     * @param array $c
     * @return array
     */
    private function withDefaults(array $c): array
    {
        $d = [
            'dry_run'           => false,
            'default_status'    => 301,
            'force_https'       => false,
            'loop_protection'   => true,
            'allowed_targets'   => [],
            'preserve_path'     => true,
            'preserve_query'    => true,
            'preserve_fragment' => true,
            'utm' => [
                'enable'               => true,
                'defaults'             => [],
                'allow_query_override' => true,
                'strip_existing'       => false,
                'auto_source_from_host' => true,
            ],
            'rules'      => [],
            'skip'       => [],
            'hooks'      => [],              // string => callable
            'middleware' => [],              // array of MiddlewareInterface
        ];
        return array_replace_recursive($d, $c);
    }

    /**
     * Build initial Context from PHP superglobals.
     *
     * @return Context
     */
    private function buildContext(): Context
    {
        $ctx = new Context();
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? null) == 443);
        $ctx->scheme = $https ? 'https' : 'http';
        $ctx->method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $ctx->host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $uri         = $_SERVER['REQUEST_URI'] ?? '/';

        $ctx->url = $ctx->scheme . '://' . $ctx->host . $uri;

        $u = parse_url($ctx->url);
        $ctx->port     = isset($u['port']) ? (int)$u['port'] : null;
        $ctx->path     = $u['path'] ?? '/';
        $ctx->fragment = $u['fragment'] ?? null;
        if (!empty($u['query'])) {
            parse_str($u['query'], $ctx->query);
        }

        // Fill IP and UA exactly from $_SERVER by default.
        $ctx->ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $ctx->ua = $_SERVER['HTTP_USER_AGENT'] ?? null;

        // Lazy headers provider
        $ctx->headers = static function (): array {
            $out = [];
            foreach ($_SERVER as $k => $v) {
                if (strpos($k, 'HTTP_') === 0) {
                    $name = strtolower(str_replace('_', '-', substr($k, 5)));
                    $out[$name] = $v;
                }
            }
            return $out;
        };

        return $ctx;
    }

    /* ========================= Matching & Target ========================= */

    /**
     * Check if haystack starts with needle.
     *
     * @param string $haystack
     * @param string $needle
     * @return bool
     */
    private function startsWith(string $haystack, string $needle): bool
    {
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }

    /**
     * Match first applicable rule based on the Context.
     *
     * @return array|null
     */
    private function matchRule(): ?array
    {
        $host = $this->ctx->host;
        $path = $this->ctx->path;
        $url  = $this->ctx->url;

        foreach ($this->cfg['rules'] as $rule) {
            $m = $rule['match'] ?? [];

            // host exact
            if (!empty($m['host'])) {
                $hosts = (array) $m['host'];
                if (!in_array($host, $hosts, true)) continue;
            }
            // host wildcard
            if (!empty($m['host_wildcard'])) {
                $pattern = (array) $m['host_wildcard'];
                $ok = false;
                foreach ($pattern as $w) {
                    $regex = '#^' . str_replace(['.', '*'], ['\.', '.*'], $w) . '$#i';
                    if (preg_match($regex, $host)) {
                        $ok = true;
                        break;
                    }
                }
                if (!$ok) continue;
            }
            // path exact
            if (!empty($m['path'])) {
                if ($path !== $m['path']) continue;
            }
            // path wildcard (single * capture as $1)
            if (!empty($m['path_wildcard'])) {
                $w = $m['path_wildcard'];
                $wRegex = '#^' . str_replace(['.', '*'], ['\.', '(.*)'], $w) . '$#';
                if (!preg_match($wRegex, $path)) continue;
            }
            // path regex
            if (!empty($m['path_regex'])) {
                if (!preg_match($m['path_regex'], $path)) continue;
            }
            // query exact subset
            if (!empty($m['query'])) {
                $allOk = true;
                foreach ($m['query'] as $k => $v) {
                    if (!array_key_exists($k, $this->ctx->query) || (string)$this->ctx->query[$k] !== (string)$v) {
                        $allOk = false;
                        break;
                    }
                }
                if (!$allOk) continue;
            }
            // full URL regex
            if (!empty($m['url_regex'])) {
                if (!preg_match($m['url_regex'], $url)) continue;
            }

            return $rule;
        }
        return null;
    }

    /**
     * Build target URL by interpolating backreferences into the rule's target.
     *
     * @param array $rule
     * @return string
     */
    private function buildTargetUrl(array $rule): string
    {
        $target = $rule['target'] ?? '';
        if ($target === '') {
            return $this->ctx->url;
        }

        $replacements = $this->captureBackrefs($rule['match'] ?? []);

        // Interpolate $1, $2 etc and {host},{path},{scheme}
        uksort($replacements, static function ($a, $b) {
            return strlen($b) <=> strlen($a);
        });
        $target = strtr($target, $replacements);

        // Relative target -> join with current host/scheme
        if (!preg_match('#^https?://#i', $target)) {
            $base = $this->ctx->scheme . '://' . $this->ctx->host;
            if ($this->ctx->port && !in_array([$this->ctx->scheme, $this->ctx->port], [['http', 80], ['https', 443]], true)) {
                $base .= ':' . $this->ctx->port;
            }
            $target = rtrim($base, '/') . '/' . ltrim($target, '/');
        }

        return $target;
    }

    /**
     * Capture regex/wildcard backreferences for target interpolation.
     *
     * @param array $match
     * @return array<string,string>
     */
    private function captureBackrefs(array $match): array
    {
        $refs = [];

        // path_regex
        if (!empty($match['path_regex'])) {
            if (preg_match($match['path_regex'], $this->ctx->path, $m)) {
                foreach ($m as $i => $val) {
                    if (is_int($i)) $refs['$' . $i] = rawurlencode($val);
                }
            }
        }

        // path_wildcard
        if (!empty($match['path_wildcard'])) {
            $w = $match['path_wildcard'];
            $wRegex = '#^' . str_replace(['.', '*'], ['\.', '(.*)'], $w) . '$#';
            if (preg_match($wRegex, $this->ctx->path, $m)) {
                foreach ($m as $i => $val) {
                    if (is_int($i)) $refs['$' . $i] = rawurlencode($val);
                }
            }
        }

        // url_regex
        if (!empty($match['url_regex'])) {
            if (preg_match($match['url_regex'], $this->ctx->url, $m)) {
                foreach ($m as $i => $val) {
                    if (is_int($i)) $refs['$' . $i] = rawurlencode($val);
                }
            }
        }

        // additional tokens
        $refs['{host}']   = $this->ctx->host;
        $refs['{path}']   = $this->ctx->path;
        $refs['{scheme}'] = $this->ctx->scheme;

        return $refs;
    }

    /**
     * Preserve query and fragment as configured and merge into the target.
     *
     * @param string $target
     * @return string
     */
    private function applyPreservation(string $target): string
    {
        $parts = parse_url($target);
        $tQuery = [];
        if (!empty($parts['query'])) parse_str($parts['query'], $tQuery);

        if ($this->cfg['preserve_query']) {
            $srcQ = $this->ctx->query;
            if ($this->cfg['utm']['strip_existing']) {
                foreach ($srcQ as $k => $v) {
                    if (stripos($k, 'utm_') === 0) unset($srcQ[$k]);
                }
            }
            $tQuery = array_merge($srcQ, $tQuery);
        }

        $fragment = $parts['fragment'] ?? null;
        if ($this->cfg['preserve_fragment'] && $this->ctx->fragment && !$fragment) {
            $fragment = $this->ctx->fragment;
        }

        $parts['query']    = http_build_query($tQuery);
        $parts['fragment'] = $fragment;

        return $this->unparseUrl($parts);
    }

    /**
     * Append/merge UTM parameters into the target according to config/rule.
     *
     * @param string $target
     * @param array $rule
     * @return string
     */
    private function applyUtms(string $target, array $rule): string
    {
        if (!$this->cfg['utm']['enable']) return $target;

        $parts  = parse_url($target);
        $tQuery = [];
        if (!empty($parts['query'])) parse_str($parts['query'], $tQuery);

        $utm = $this->cfg['utm']['defaults'] ?? [];
        if (!empty($rule['utm'])) {
            $utm = array_merge($utm, $rule['utm']);
        }
        if ($this->cfg['utm']['auto_source_from_host'] && empty($utm['utm_source'])) {
            $utm['utm_source'] = $this->sanitizeHost($this->ctx->host);
        }
        if ($this->cfg['utm']['allow_query_override']) {
            foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $k) {
                if (isset($this->ctx->query[$k]) && $this->ctx->query[$k] !== '') {
                    $utm[$k] = $this->ctx->query[$k];
                }
            }
        }

        // Hooks around UTM (allow returning modified values)
        $ret = $this->hook('beforeApplyUtms', $this->ctx, $rule, $target, $utm);
        if (is_array($ret)) {
            if (isset($ret['target']) && is_string($ret['target'])) $target = $ret['target'];
            if (isset($ret['utm']) && is_array($ret['utm'])) $utm = $ret['utm'];
        }

        $tQuery = array_merge($tQuery, $utm);
        $parts['query'] = http_build_query($tQuery);

        $final = $this->unparseUrl($parts);
        $ret2  = $this->hook('afterApplyUtms', $this->ctx, $rule, $final, $utm);
        if (is_string($ret2) && $ret2 !== '') {
            // If hook returned a string, use it as the final target URL
            $final = $ret2;
        }

        return $final;
    }

    /**
     * Force HTTPS for the given URL.
     *
     * @param string $url
     * @param bool $dropDefaultPorts Whether to drop default ports.
     * @param array|null $portMap Optional port mapping (e.g. [80 => 8080, 443 => null] to drop).
     * @return string
     */
    private function forceHttps(string $url, bool $dropDefaultPorts = true, ?array $portMap = null): string
    {
        $p = parse_url($url);

        // Guard: only absolute http(s) URLs with host
        $scheme = isset($p['scheme']) ? strtolower($p['scheme']) : null;
        if (!in_array($scheme, ['http', 'https'], true) || empty($p['host'])) {
            return $url; // leave untouched
        }

        $origPort   = $p['port'] ?? null;
        $p['scheme'] = 'https';

        // Port mapping (explicit policy wins)
        if ($portMap !== null && $origPort !== null && array_key_exists($origPort, $portMap)) {
            $mapped = $portMap[$origPort];
            if ($mapped === null) {
                unset($p['port']);
            } else {
                $p['port'] = (int)$mapped;
            }
        } else {
            // No mapping: keep non-defaults, drop defaults
            if (isset($p['port'])) {
                if ($dropDefaultPorts && ((int)$p['port'] === 80 || (int)$p['port'] === 443)) {
                    unset($p['port']);
                }
                // else: leave custom ports as-is
            }
        }

        return $this->unparseUrl($p);
    }

    /**
     * Ensure target host is in allowlist if provided.
     *
     * @param string $url
     * @return void
     */
    private function assertAllowedTarget(string $url): void
    {
        if (empty($this->cfg['allowed_targets'])) return;
        // Parse the target URL to extract the host
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            $this->hook('onError', $this->ctx, new \RuntimeException('Target host not allowed: empty'));
            throw new \RuntimeException('Target host not allowed: empty');
        }
        // Check against allowlist
        $allowed = array_map('strtolower', (array) $this->cfg['allowed_targets']);
        if (!in_array($host, $allowed, true)) {
            $this->hook('onError', $this->ctx, new \RuntimeException("Target host not allowed: $host"));
            throw new \RuntimeException("Target host not allowed: $host");
        }
    }

    /**
     * Compare two URLs after normalization.
     *
     * @param string $a
     * @param string $b
     * @return bool
     */
    private function urlsEqual(string $a, string $b): bool
    {
        return $this->normalizeUrl($a) === $this->normalizeUrl($b);
    }

    /**
     * Normalize URL for comparison (lowercase scheme/host, sorted query).
     *
     * @param string $u
     * @return string
     */
    private function normalizeUrl(string $u): string
    {
        $p = parse_url($u);
        $scheme = strtolower($p['scheme'] ?? 'http');
        $host   = strtolower($p['host'] ?? '');
        $path   = $p['path'] ?? '/';
        $query  = [];
        if (!empty($p['query'])) parse_str($p['query'], $query);
        ksort($query);
        $q = http_build_query($query);

        return $scheme . '://' . $host . $path . ($q ? '?' . $q : '') . (isset($p['fragment']) ? '#' . $p['fragment'] : '');
    }

    /**
     * Sanitize host for safe UTM source, allowing letters, digits, dots and hyphens.
     *
     * @param string $host
     * @return string
     */
    private function sanitizeHost(string $host): string
    {
        return strtolower((string)preg_replace('~[^a-z0-9\.\-]+~i', '', $host));
    }

    /**
     * Recompose URL array back into string.
     *
     * @param array $p
     * @return string
     */
    private function unparseUrl(array $p): string
    {
        $scheme   = $p['scheme'] ?? null;
        $host     = $p['host'] ?? null;
        $port     = isset($p['port']) ? ':' . $p['port'] : '';
        $user     = $p['user'] ?? null;
        $pass     = isset($p['pass']) ? ':' . $p['pass']  : '';
        $pass     = ($user || $pass) ? "$pass@" : '';
        $path     = $p['path'] ?? '';
        $query    = isset($p['query']) && $p['query'] !== '' ? '?' . $p['query'] : '';
        $fragment = isset($p['fragment']) ? '#' . $p['fragment'] : '';

        return ($scheme ? "$scheme://" : '') .
            ($user ? "$user$pass" : '') .
            ($host ? $host : '') .
            $port . $path . $query . $fragment;
    }

    /* ============================= Hooks ============================= */

    /**
     * Invoke a hook if registered.
     *
     * @param string $name
     * @param mixed ...$args
     * @return mixed|null
     */
    private function hook(string $name, mixed ...$args)
    {
        if (!isset($this->cfg['hooks'][$name])) return null;
        $cb = $this->cfg['hooks'][$name];
        if (!is_callable($cb)) return null;
        try {
            return $cb(...$args);
        } catch (\Throwable $e) {
            if (isset($this->cfg['hooks']['onError']) && is_callable($this->cfg['hooks']['onError'])) {
                ($this->cfg['hooks']['onError'])($args[0] ?? null, $e);
            }
            return null;
        }
    }

    /* ============================ Middleware ============================ */

    /**
     * Run middleware pipeline. Each middleware may inspect/modify ctx/rule/target/status.
     * If any middleware returns FALSE, the pipeline short-circuits and run() returns.
     *
     * @param Context $ctx
     * @param array $rule
     * @param string $target
     * @param int $status
     * @return bool TRUE if pipeline completed, FALSE if short-circuited.
     */
    private function runMiddlewarePipeline(Context &$ctx, array &$rule, string &$target, int &$status): bool
    {
        $stack = $this->cfg['middleware'];
        $i = 0;

        $next = function (Context &$ctx, ?array &$rule, ?string &$target, ?int &$status) use (&$stack, &$i, &$next): bool {
            if ($i >= count($stack)) {
                return true;
            }
            /** @var MiddlewareInterface $mw */
            $mw = $stack[$i++];
            return $mw->process($ctx, $rule, $target, $status, $next);
        };

        return $next($ctx, $rule, $target, $status);
    }

    /* ============================== Debug ============================== */

    /**
     * Output debug JSON in dry-run mode.
     *
     * @param array $rule
     * @param string $final
     * @param int $status
     * @return void
     */
    private function debugOutput(array $rule, string $final, int $status): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'request' => [
                'method'   => $this->ctx->method,
                'url'      => $this->ctx->url,
                'scheme'   => $this->ctx->scheme,
                'host'     => $this->ctx->host,
                'path'     => $this->ctx->path,
                'query'    => $this->ctx->query,
                'fragment' => $this->ctx->fragment,
                'ip'       => $this->ctx->ip,
                'ua'       => $this->ctx->ua,
            ],
            'matched_rule' => $rule['name'] ?? '(unnamed)',
            'status'       => $status,
            'target'       => $final,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Emit simple text response with given status code.
     *
     * @param int $code
     * @param string $msg
     * @return void
     */
    private function respond(int $code, string $msg): void
    {
        http_response_code($code);
        header('Content-Type: text/plain; charset=utf-8');
        echo $msg;
        exit;
    }
}
