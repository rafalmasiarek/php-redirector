# Universal Redirector (PSR-4)

A highly configurable **PHP redirect engine** with:
- Domain and URI redirects (exact, wildcard, regex)
- Per-rule status codes (301, 302, 307, 308)
- UTM appending (GA/GA4 friendly) with global defaults and per-rule overrides
- Lifecycle **hooks** for custom actions
- **Middleware pipeline** (PSR-inspired) for extensibility
- Loop protection and target allowlist
- Dry-run mode with JSON debug output
- PSR-4 autoloadable, Composer-ready

---

## Installation

With Composer:
```
$ composer require rafalmasiarek/redirector
```

Or include manually:
```
require __DIR__ . "/src/Redirector.php";
```

---

## Quickstart (Apache + PHP)

**.htaccess**
```
RewriteEngine On
RewriteCond %{REQUEST_URI} !^/redirector\.php$ [NC]
RewriteRule ^(.*)$ /redirector.php [L,QSA]
```

**redirector.php**
```php
<?php
require __DIR__ . "/vendor/autoload.php";

use rafalmasiarek\Redirector\Redirector;

$config = [
  "default_status" => 301,
  "no_match" => [
    "status" => 404,
    "body"   => "Redirect rule not found",
  ],
  "utm" => [
    "enable" => true,
    "defaults" => [
      "utm_source"   => "old-domain",
      "utm_medium"   => "redirect",
      "utm_campaign" => "domain-migration",
    ],
  ],
  "rules" => [
    [
      "name"   => "domain-move",
      "match"  => [
        "host" => ["old.example.com","www.old.example.com"],
        "path_regex" => "#^/(.*)$#",
      ],
      "target" => "https://new.example.com/$1",
      "status" => 301,
    ],
  ],
];

Redirector::make($config)->run();
```

---

## More advanced configuration

*(ensure namespaces)*
```php
use rafalmasiarek\Redirector\Redirector;
use rafalmasiarek\Redirector\Context;
use rafalmasiarek\Redirector\Middleware\ServerGlobalsMiddleware;
use rafalmasiarek\Redirector\Middleware\MiddlewareInterface;
```

1) **Measure redirect execution time, log matched rules, and handle missing rules or unexpected errors.**
```php
<?php
require __DIR__ . "/vendor/autoload.php";

use rafalmasiarek\Redirector\Redirector;
use rafalmasiarek\Redirector\Middleware\ServerGlobalsMiddleware;

$config = [
    "default_status" => 301,
    "middleware" => [
        new ServerGlobalsMiddleware(),
    ],

    "rules" => [
        [
            "name"   => "example-rule",
            "match"  => [ "path_regex" => "#^/old/(.*)$#" ],
            "target" => "/new/$1",
            "status" => 301,
        ],
    ],

    "hooks" => [
        "beforeMatch" => function($ctx) {
            $ctx->meta["start_time"] = microtime(true);
        },
        "afterMatch" => function($ctx, $rule) {
            if ($rule) {
                error_log("Matched rule: " . ($rule["name"] ?? "unnamed") . " for URL " . $ctx->url);
            }
        },
        "onNoMatch" => function($ctx) {
            error_log("No redirect rule matched for URL: " . $ctx->url);
            http_response_code(404);
            echo "Redirect rule not found";
            exit;
        },
        "beforeSend" => function($ctx, $rule, $target, $status) {
            $elapsed = microtime(true) - ($ctx->meta["start_time"] ?? microtime(true));
            error_log(sprintf(
                "Redirecting %s → %s [%d] in %.4f sec",
                $ctx->url,
                $target,
                $status,
                $elapsed
            ));
        },
        "onError" => function($ctx, Throwable $e) {
            error_log("Redirector error at {$ctx->url}: " . $e->getMessage());
            http_response_code(500);
            echo "Internal redirect error";
            exit;
        },
    ],
];

Redirector::make($config)->run();
```

Instead of using only hooks, you can encapsulate timing, logging, and error handling in a **custom middleware**.
```php
<?php
require __DIR__ . "/vendor/autoload.php";

use rafalmasiarek\Redirector\Redirector;
use rafalmasiarek\Redirector\Context;
use rafalmasiarek\Redirector\Middleware\ServerGlobalsMiddleware;
use rafalmasiarek\Redirector\Middleware\MiddlewareInterface;

class LoggingMiddleware implements MiddlewareInterface
{
    public function process(Context &$ctx, ?array &$rule, ?string &$target, ?int &$status, callable $next): bool
    {
        $ctx->meta["start_time"] = microtime(true);

        try {
            $result = $next($ctx, $rule, $target, $status);

            if ($rule === null) {
                error_log("No redirect rule matched for URL: " . $ctx->url);
                http_response_code(404);
                echo "Redirect rule not found";
                return false;
            }

            $elapsed = microtime(true) - $ctx->meta["start_time"];
            error_log(sprintf(
                "Redirecting %s → %s [%d] in %.4f sec",
                $ctx->url, $target, $status ?? 0, $elapsed
            ));

            return $result;
        } catch (\Throwable $e) {
            error_log("Redirector error at {$ctx->url}: " . $e->getMessage());
            http_response_code(500);
            echo "Internal redirect error";
            return false;
        }
    }
}

$config = [
    "default_status" => 301,
    "middleware" => [
        new ServerGlobalsMiddleware(),
        new LoggingMiddleware(),
    ],
    "rules" => [
        [
            "name"   => "example-rule",
            "match"  => [ "path_regex" => "#^/old/(.*)$#" ],
            "target" => "/new/$1",
            "status" => 301,
        ],
    ],
];

Redirector::make($config)->run();
```

2) **Block country with helper function**
```php
<?php
require __DIR__ . '/vendor/autoload.php';

use rafalmasiarek\Redirector\Redirector;

$config = [
  'default_status' => 301,
  'rules' => [
    [
      'name'   => 'any-redirect',
      'match'  => ['path_regex' => '#^/(.*)$#'],
      'target' => 'https://www.example.com/$1',
      'status' => 301,
    ],
  ],
  'hooks' => [
    'beforeSend' => function($ctx, $rule, $target, $status) {
        $headers = is_callable($ctx->headers) ? ($ctx->headers)() : [];
        $h = array_change_key_case($headers, CASE_LOWER);
        $country =
            ($h['cloudfront-viewer-country'] ?? null) ?:
            ($h['cf-ipcountry'] ?? null) ?:
            ($h['x-geo-country'] ?? null) ?:
            ($_SERVER['GEOIP_COUNTRY_CODE'] ?? null) ?:
            ($_SERVER['GEOIP2_COUNTRY_ISO_CODE'] ?? null);
        $cc = $country ? strtoupper($country) : null;

        $blocked = ['RU','BY'];
        if ($cc && in_array($cc, $blocked, true)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Access forbidden in your region.';
            return false;
        }
        return true;
    },
  ],
];

Redirector::make($config)->run();
```

3) **Geofencing redirect with middleware**
```php
<?php
require __DIR__ . '/vendor/autoload.php';

use rafalmasiarek\Redirector\Redirector;
use rafalmasiarek\Redirector\Context;
use rafalmasiarek\Redirector\Middleware\MiddlewareInterface;

class GeoRedirectMiddleware implements MiddlewareInterface
{
    public function process(Context &$ctx, ?array &$rule, ?string &$target, ?int &$status, callable $next): bool
    {
        $ok = $next($ctx, $rule, $target, $status);
        if (!$ok) return false;

        $headers = is_callable($ctx->headers) ? ($ctx->headers)() : [];
        $h = array_change_key_case($headers, CASE_LOWER);
        $country =
            ($h['cloudfront-viewer-country'] ?? null) ?:
            ($h['cf-ipcountry'] ?? null) ?:
            ($h['x-geo-country'] ?? null) ?:
            ($_SERVER['GEOIP_COUNTRY_CODE'] ?? null) ?:
            ($_SERVER['GEOIP2_COUNTRY_ISO_CODE'] ?? null);
        $cc = $country ? strtoupper($country) : null;

        if ($cc === 'DE' || $cc === 'FR') {
            $map = ['DE' => 'de.example.com', 'FR' => 'fr.example.com'];
            $host = $map[$cc];

            $p = parse_url($target);
            if (!empty($p['scheme']) && !empty($p['host'])) {
                $p['host'] = $host;
                $target = $this->unparseUrl($p);
            } else {
                $base = $ctx->scheme . '://' . $host;
                $target = rtrim($base, '/') . '/' . ltrim($target, '/');
            }

            if (empty($status) || $status === 301) {
                $status = 302;
            }
        }

        return true;
    }

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
}

$config = [
  'default_status' => 301,
  'middleware' => [ new GeoRedirectMiddleware() ],
  'rules' => [
    [
      'name'   => 'to-www',
      'match'  => ['path_regex' => '#^/(.*)$#'],
      'target' => 'https://www.example.com/$1',
      'status' => 301,
    ],
  ],
];

Redirector::make($config)->run();
```

---

## Configuration Reference

- **dry_run** (bool): when true, outputs JSON instead of sending `Location`
- **default_status** (int): default 301/302/307/308
- **force_https** (bool): force HTTPS in the final URL; **drops default ports** based on `default_ports`; respects `port_map` if provided
- **default_ports** (array): default ports per scheme, e.g. `["http" => 80, "https" => 443]`
- **port_map** (array|null): optional mapping when switching to HTTPS, e.g. `[80 => null, 8080 => 8443]`; `null` value means “drop port”
- **loop_protection** (array|false): response when a redirect loop is detected
  - `status` (int)
  - `body` (string)
- **allowed_targets** (string[]): hostname allowlist for target validation (case-insensitive)
- **preserve_path/query/fragment** (bool): carry components from the source request into the target (see below)
- **no_match** (array): fallback response when no rule matches and no `onNoMatch` hook is set
  - `status` (int)
  - `body` (string)
- **utm** (array):
  - `enable` (bool)
  - `defaults` (array): e.g. `utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`
  - `allow_query_override` (bool): allow incoming `?utm_*` to override defaults
  - `strip_existing` (bool): strip existing `utm_*` from preserved query
  - `auto_source_from_host` (bool): derive `utm_source` from the source host if missing
- **rules** (array): first match wins
  - `match`: `host` | `host_wildcard` | `path` | `path_wildcard` | `path_regex` | `query` | `url_regex`
  - `target`: supports `$1..$n` (percent-encoded) and tokens `{host}`, `{path}`, `{scheme}`
  - `status`: 301|302|307|308
  - `utm`: per-rule UTM overrides
- **skip** (string[]): paths to skip (no redirect)
  - Empty strings in skip are ignored.
  - A skip entry matches by prefix (path starts with the entry).
- **hooks** (map<string, callable>): lifecycle callbacks
- **middleware** (MiddlewareInterface[]): PSR-style pipeline

---

## Preserve-path semantics

When `preserve_path` is enabled and the computed target URL **has no explicit path** (empty or `/`), the redirector **preserves the source path** (`$ctx->path`) in the final target.

**Examples**
- Source: `GET http://old.tld/products/42`, rule target: `https://new.tld`  
  → Result: `https://new.tld/products/42`
- Source: `GET http://old.tld/`, rule target: `https://new.tld`  
  → Result: `https://new.tld/`
- If the rule target already has a path (e.g. `https://new.tld/shop`), nothing is changed.

Order: after building the absolute target, **before** query/fragment preservation and UTMs.

---

## Ports & HTTPS behavior

- For **relative targets**, the redirector joins the current `scheme://host` and appends the current port **only if it differs** from the scheme’s default in `default_ports`.
- With `force_https: true`, the scheme is switched to HTTPS and the port is handled as follows:
  - If `port_map` contains the original port, it is applied (use `null` to drop it).
  - Otherwise, if dropping defaults is enabled (by implementation), the port is removed if it equals **either** `default_ports['http']` or `default_ports['https']`. Non-default ports are kept.

---

## Loop-protection behavior

When the computed target URL equals the current request URL (a redirect loop), the redirect is **skipped** and a configurable response is returned via `loop_protection`.

**Default**
```php
'loop_protection' => [
  'status' => 204,
  'body'   => 'Already at target (loop protection).',
],
```

**Disable**
```php
'loop_protection' => [],   // or: false
```

**Custom**
```php
'loop_protection' => [
  'status' => 200,
  'body'   => 'OK',
],
```

Notes:
- Applied only when a loop is detected.
- When triggered, no `Location` header is sent.

---

## Hooks

All hooks are optional.

### Return conventions
- Hooks that return `?string`: returning a **non-empty** string overrides the corresponding value; `null`/`''` leaves it unchanged.
- Hooks that return an array shape: only provided keys are applied; others are left unchanged.
- Hooks not listed with a return type are `void`; any return value is ignored.

### Return-value semantics
- `afterBuildTarget($ctx, $rule, $target): ?string`
- `beforeApplyUtms($ctx, $rule, $target, $utm): array{target?: string, utm?: array}`
- `afterApplyUtms($ctx, $rule, $finalTarget, $utm): ?string`
- `beforeSend($ctx, $rule, &$target, &$status)` — pass-by-ref; return `false` to take over the response

### Available hooks
- `onSkip($ctx)`
- `beforeMatch($ctx)`
- `afterMatch($ctx, $rule)`
- `onNoMatch($ctx)`
- `beforeBuildTarget($ctx, $rule)`
- `afterBuildTarget($ctx, $rule, $target): ?string`
- `beforeApplyUtms($ctx, $rule, $target, $utm): array{target?: string, utm?: array}`
- `afterApplyUtms($ctx, $rule, $finalTarget, $utm): ?string`
- `beforeSend($ctx, $rule, &$target, &$status)`
- `onDryRun($ctx, $rule, $target, $status)`
- `onError($ctx, \Throwable $e)`

---

## Middleware

**Interface**
```php
namespace rafalmasiarek\Redirector\Middleware;

use rafalmasiarek\Redirector\Context;

interface MiddlewareInterface {
  public function process(
    Context &$ctx, ?array &$rule, ?string &$target, ?int &$status, callable $next
  ): bool;
}
```

**Default middleware**
```php
use rafalmasiarek\Redirector\Middleware\ServerGlobalsMiddleware;
// Populates $ctx->ip and $ctx->ua from $_SERVER and exposes a lazy headers reader.
```

**Register**
```php
"middleware" => [
  new \rafalmasiarek\Redirector\Middleware\ServerGlobalsMiddleware(),
],
```

---

## Example Rules

1) **Domain migration with UTMs**
```
[
  "name"   => "domain-migration",
  "match"  => ["host" => ["old.com","www.old.com"], "path_regex" => "#^/(.*)$#"],
  "target" => "https://new.com/$1",
  "status" => 301,
  "utm"    => ["utm_source"=>"old.com","utm_medium"=>"redirect","utm_campaign"=>"domain-migration"],
]
```

2) **Exact path → new location**
```
[
  "name"   => "old-article",
  "match"  => ["path" => "/blog/old-article"],
  "target" => "/blog/new-article",
  "status" => 308,
  "utm"    => ["utm_campaign"=>"content-migration"],
]
```

3) **Wildcard section**
```
[
  "name"   => "docs-move",
  "match"  => ["path_wildcard" => "/docs/*"],
  "target" => "/knowledge-base/$1",
  "status" => 301
]
```

4) **Regex: product route remap**
```
[
  "name"   => "product-regex",
  "match"  => ["path_regex" => "#^/shop/([0-9]+)/.*$#i"],
  "target" => "/store/product/$1",
  "status" => 301
]
```

5) **Subdomain consolidation**
```
[
  "name"   => "subdomain-merge",
  "match"  => ["host_wildcard" => "*.oldsite.com", "path_regex" => "#^/(.*)$#"],
  "target" => "https://newsite.com/$1",
  "status" => 301,
  "utm"    => ["utm_source"=>"oldsite.com","utm_medium"=>"redirect","utm_campaign"=>"subdomain-merge"],
]
```

6) **Query-based landing**
```
[
  "name"   => "partner-ref",
  "match"  => ["query" => ["ref" => "partner"]],
  "target" => "/landing/partner",
  "status" => 302,
  "utm"    => ["utm_source"=>"partner","utm_medium"=>"referral","utm_campaign"=>"partner-campaign"],
]
```

7) **Full URL regex escape hatch**
```
[
  "name"   => "legacy-paths",
  "match"  => ["url_regex" => "#^https?://(www\.)?legacy\.tld/legacy/(.*)$#i"],
  "target" => "https://modern.tld/new/$2",
  "status" => 301
]
```

8) **Force trailing slash (hook)**
```php
"hooks" => [
  "afterBuildTarget" => function($ctx, $rule, $target) {
    $p = parse_url($target);
    $path  = rtrim($p["path"] ?? "", "/") . "/";
    $query = isset($p["query"]) && $p["query"] !== "" ? "?".$p["query"] : "";
    return ($p["scheme"]."://".$p["host"]).$path.$query;
  },
],
```

9) **A/B UTM content (hook)**
```php
"hooks" => [
  "beforeApplyUtms" => function($ctx, $rule, $target, $utm) {
    if (($_COOKIE["ab"] ?? "A") === "B") {
      $utm["utm_content"] = "variant-b";
    }
    return ["utm" => $utm];
  },
],
```

10) **Time-based status (middleware)**
```php
new class implements \rafalmasiarek\Redirector\Middleware\MiddlewareInterface {
  public function process($ctx, &$rule, &$target, &$status, $next): bool {
    $ok = $next($ctx, $rule, $target, $status);
    $hour = (int)date("G");
    if ($hour >= 22 || $hour < 6) $status = 302; // night: temporary
    return $ok;
  }
}
```

11) **Skip health & static**
```
"skip" => ["/robots.txt","/sitemap.xml","/health"],
```

12) **Target allowlist (security)**
```
"allowed_targets" => ["new.com","cdn.new.com"],
```

---

## Testing

Enable dry run:
```
"dry_run" => true
```
You will get a JSON with request context, matched rule, target and status instead of a Location header.

---

# Migration: 1.0.0 → 1.1.0

This release focuses on safety and clarity. Most apps won’t need code changes **unless** you:
- modify URLs via hooks,
- asserted the old loop-protection status,
- or depended on **raw** (unencoded) regex captures.

## TL;DR checklist

- [ ] If you mutate `$target/$utm` inside hooks — **migrate to the new return-value contract** (see “Breaking changes #1”).
- [ ] If you asserted **208** for loop-protection — update to **204** or set your own status/body in `loop_protection`.
- [ ] If you expected **raw (unencoded)** `$1..$n` — captures are now `rawurlencode()`d.
- [ ] If you have mixed-case hosts in `allowed_targets` — comparison is now **lowercase**.
- [ ] If your `skip` list ever contained an empty string — it is now **ignored** (no longer matches everything).
- [ ] If you want custom “no match” behavior — use the new `no_match` config.
- [ ] If you rely on `beforeSend` mutating by reference — **ensure your hook invocation preserves references** (see note below).

## Breaking changes

### 1) Hook contract: return values instead of pass-by-reference

**Was (1.0.0):** examples modified `&$target` / `&$utm` by reference (but those hooks weren’t actually applied by ref).

**Now (1.1.0):**
- `afterBuildTarget($ctx, $rule, $target): ?string` — return a non-empty string to override `$target`.
- `beforeApplyUtms($ctx, $rule, $target, $utm): array{target?:string, utm?:array}` — return partial overrides.
- `afterApplyUtms($ctx, $rule, $finalTarget, $utm): ?string` — return a non-empty string to override the final URL.
- `beforeSend($ctx, $rule, &$target, &$status)` **stays by reference** by contract; return `false` to take over the response.

> **Important:** If your `hook()` dispatcher uses a generic `...$args` call, PHP will **not** preserve references. Either (a) pass refs explicitly for `beforeSend`, or (b) adopt the return-value pattern there as well.

**Migrate your hooks:**
```php
// 1.0.0 (old style — by-ref; do not use on these hooks anymore)
'hooks' => [
  'afterBuildTarget' => function ($ctx, $rule, &$target) {
    $target .= (str_contains($target, '?') ? '&' : '?') . 'extra=1';
  },
  'beforeApplyUtms' => function ($ctx, $rule, &$target, &$utm) {
    $utm['utm_content'] = 'variant-b';
  },
],

// 1.1.0 (new style — return values)
'hooks' => [
  'afterBuildTarget' => function ($ctx, $rule, $target) {
    return $target . (str_contains($target, '?') ? '&' : '?') . 'extra=1';
  },
  'beforeApplyUtms' => function ($ctx, $rule, $target, $utm) {
    $utm['utm_content'] = 'variant-b';
    return ['utm' => $utm];
  },
  'afterApplyUtms' => function ($ctx, $rule, $final, $utm) {
    return $final; // or null to keep unchanged
  },
],
```

### 2) Backreferences are now URL-encoded

- Regex/wildcard captures (`$1..$n`) interpolated into `target` are now **`rawurlencode()`d** for safety.
- If you relied on raw insertion, update your rules or adjust via hooks (decoding is **not recommended**).

---

## Behavior changes (aligned with current code)

- **Loop protection:** configured via:
  ```php
  'loop_protection' => ['status' => 204, 'body' => 'Already at target (loop protection).']
  ```
  It should be **applied only when** the computed `target` equals the current request URL.  
  > If your code currently responds unconditionally, re-add the guard:
  ```php
  if ($this->urlsEqual($ctx->url, $target)) {
      $lp = $this->cfg['loop_protection'];
      $this->respond($lp['status'], $lp['body']);
      return;
  }
  ```

- **Force HTTPS & ports:** when switching to HTTPS, **default ports defined in `default_ports` are dropped** (typically `:80` and `:443`).  
  Non-default ports are kept unless you explicitly remap via `port_map`.

- **No-match default:** still 204 by default, now configurable via `no_match`.

- **`preserve_path`:** now **applied** — if a rule’s `target` has no explicit path (empty or `/`), the **source path is preserved**.

- **`allowed_targets`:** hostnames are normalized to **lowercase** before comparison.

- **Skip list guard:** empty strings in `skip` are **ignored**.  
  > If needed, also guard in code:
  ```php
  private function startsWith(string $h, string $n): bool {
      if ($n === '') return false;
      return strncmp($h, $n, strlen($n)) === 0;
  }
  ```

---

## New / updated configuration

```php
[
  // Custom fallback when no rule matches
  'no_match' => [
    'status' => 204,
    'body'   => 'No redirect (no rules matched).',
  ],

  // Loop protection response (used when target == source)
  'loop_protection' => [
    'status' => 204,
    'body'   => 'Already at target (loop protection).',
  ],

  // Ports & HTTPS policy
  'default_ports' => ['http' => 80, 'https' => 443],
  'port_map'      => null, // e.g. [80 => null, 8080 => 8443]; null value = drop port

  // Path/query/fragment preservation
  'preserve_path'     => true,
  'preserve_query'    => true,
  'preserve_fragment' => true,
]
```

---

## Preserve-path semantics (explicit)

When `preserve_path` is enabled and the computed target URL **has no explicit path** (empty or `/`), the final URL **reuses the source path** (`$ctx->path`).

Examples:
- Source `http://old.tld/products/42` + target `https://new.tld` → `https://new.tld/products/42`
- Source `http://old.tld/` + target `https://new.tld` → `https://new.tld/`
- If target already contains a path (`https://new.tld/shop`), nothing is changed.

Order: after making the target absolute, **before** query/fragment preservation and UTMs.

---

## Notes for tests & monitoring

- Update assertions that checked for **208** in loop-protection to **204** (or assert your configured status/body).
- If you snapshot URLs with captures, expect **percent-encoding** now.
- Normalize fixtures for `allowed_targets` to lowercase.
- If you rely on `beforeSend` mutations, ensure your hook invocation preserves **by-ref** semantics (or refactor that hook to return values).

---

## License

MIT