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

use rafalmasiarek\Redirector;

$config = [
  "default_status" => 301,
  "no_match" => [
    'status' => 404,
    'body'   => 'Redirect rule not found',
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

UniversalRedirector::make($config)->run();
```
---

## More advanced configuration

1) **Measure redirect execution time, log matched rules, and handle missing rules or unexpected errors.**

```php
<?php
require __DIR__ . "/vendor/autoload.php";

use rafalmasiarek\Redirector;

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

        // 1. Start timer before matching rules
        "beforeMatch" => function($ctx) {
            $ctx->meta["start_time"] = microtime(true);
        },

        // 2. Log when rule is matched or not
        "afterMatch" => function($ctx, $rule) {
            if ($rule) {
                error_log("Matched rule: " . ($rule["name"] ?? "unnamed") . " for URL " . $ctx->url);
            }
        },

        // 3. If no rule matched → log error and throw exception
        "onNoMatch" => function($ctx) {
            error_log("No redirect rule matched for URL: " . $ctx->url);
            http_response_code(404);
            echo "Redirect rule not found";
            exit;
        },

        // 4. Measure total redirect processing time
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

        // 5. Handle unexpected errors
        "onError" => function($ctx, Throwable $e) {
            error_log("Redirector error at {$ctx->url}: " . $e->getMessage());
            http_response_code(500);
            echo "Internal redirect error";
            exit;
        },
    ],
];

UniversalRedirector::make($config)->run();
```

Instead of using only hooks, you can encapsulate timing, logging, and error handling in a **custom middleware**.  
This approach keeps the redirect configuration cleaner and makes the logic reusable across projects.

```php
<?php
require __DIR__ . "/vendor/autoload.php";

use rafalmasiarek\Redirector;
use rafalmasiarek\Redirector\Context;
use rafalmasiarek\Middleware\ServerGlobalsMiddleware;
use rafalmasiarek\Middleware\MiddlewareInterface;

// Custom middleware
class LoggingMiddleware implements MiddlewareInterface
{
    public function process(Context &$ctx, ?array &$rule, ?string &$target, ?int &$status, callable $next): bool
    {
        // Start timing
        $ctx->meta["start_time"] = microtime(true);

        try {
            // Run next middleware + redirect logic
            $result = $next($ctx, $rule, $target, $status);

            // No rule matched → error handling
            if ($rule === null) {
                error_log("No redirect rule matched for URL: " . $ctx->url);
                http_response_code(404);
                echo "Redirect rule not found";
                return false; // stop pipeline
            }

            // Log timing before sending redirect
            $elapsed = microtime(true) - $ctx->meta["start_time"];
            error_log(sprintf(
                "Redirecting %s → %s [%d] in %.4f sec",
                $ctx->url,
                $target,
                $status ?? 0,
                $elapsed
            ));

            return $result;
        } catch (\Throwable $e) {
            // Global error handling
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
        new LoggingMiddleware(), // our custom timing & logging
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

UniversalRedirector::make($config)->run();
```

2) **Block country with helper function** 

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use rafalmasiarek\Redirector;

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
        $getCountry = function() use ($ctx) {
            $headers = is_callable($ctx->headers) ? ($ctx->headers)() : [];
            $h = array_change_key_case($headers, CASE_LOWER);
            $country =
                ($h['cloudfront-viewer-country'] ?? null) ?:   // AWS CloudFront
                ($h['cf-ipcountry'] ?? null) ?:                // Cloudflare
                ($h['x-geo-country'] ?? null) ?:               // inne CDN/proxy
                ($_SERVER['GEOIP_COUNTRY_CODE'] ?? null) ?:    // mod_geoip
                ($_SERVER['GEOIP2_COUNTRY_ISO_CODE'] ?? null); // mod_maxminddb
            return $country ? strtoupper($country) : null;
        };

        $blocked = ['RU','BY'];
        $cc = $getCountry();

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

UniversalRedirector::make($config)->run();
```

3) **Geofencing redirect with middleware**

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use rafalmasiarek\Redirector;
use rafalmasiarek\Redirector\Context;
use rafalmasiarek\Redirector\Middleware\MiddlewareInterface;

// Middleware geo-redirect
class GeoRedirectMiddleware implements MiddlewareInterface
{
    public function process(Context &$ctx, ?array &$rule, ?string &$target, ?int &$status, callable $next): bool
    {
        $ok = $next($ctx, $rule, $target, $status);
        if (!$ok) {
            return false;
        }

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
            $map = [
                'DE' => 'de.example.com',
                'FR' => 'fr.example.com',
            ];
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

  'middleware' => [
    new GeoRedirectMiddleware(),
  ],

  'rules' => [
    [
      'name'   => 'to-www',
      'match'  => ['path_regex' => '#^/(.*)$#'],
      'target' => 'https://www.example.com/$1',
      'status' => 301,
    ],
  ],
];

UniversalRedirector::make($config)->run()
```

## Configuration Reference

- **dry_run** (bool): when true, outputs JSON instead of sending `Location`
- **default_status** (int): default 301/302/307/308
- **force_https** (bool): force https in target URL
- **loop_protection** (bool): avoid redirecting to the same URL
- **allowed_targets** (string[]): hostname allowlist for target validation
- **preserve_path/query/fragment** (bool): carry components to target
- **no_match** (array): fallback response when no rule matches and no `onNoMatch` hook is set; 
  - `status` (int) 
  - `body` (string) 
- **utm** (array):
  - `enable` (bool)
  - `defaults` (array): e.g. `utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`
  - `allow_query_override` (bool): allow incoming `?utm_*` to override defaults
  - `strip_existing` (bool): strip existing `utm_*` from preserved query
  - `auto_source_from_host` (bool): fallback `utm_source` from source host
- **rules** (array of maps): first match wins
  - `match`: `host` | `host_wildcard` | `path` | `path_wildcard` | `path_regex` | `query` | `url_regex`
  - `target`: supports `$1..$n` from regex/wildcard and tokens `{host}`, `{path}`, `{scheme}`
  - `status`: 301|302|307|308
  - `utm`: per-rule UTM overrides
- **skip** (string[]): paths to skip (no redirect)
- **hooks** (map<string, callable>): lifecycle callbacks
- **middleware** (MiddlewareInterface[]): PSR-style pipeline

---

## Hooks

All hooks are optional.

### Return conventions
- Hooks that return **`?string`**: returning a **non-empty string** overrides the corresponding value; returning `null` or `''` leaves it unchanged.
- Hooks that return an **array shape**: only provided keys are applied; others are left unchanged.
- Hooks not listed with a return type are **void**; any return value is ignored.

### Return-value semantics
- `afterBuildTarget($ctx, $rule, $target): ?string` — return a non-empty string to override `$target`.
- `beforeApplyUtms($ctx, $rule, $target, $utm): array{target?: string, utm?: array}` — return partial overrides for `$target` and/or `$utm`.
- `afterApplyUtms($ctx, $rule, $finalTarget, $utm): ?string` — return a non-empty string to override the final URL.

> Note: `beforeSend()` keeps pass-by-reference params so it can mutate `$target` / `$status`. Return `false` to take over the response (short-circuit). Any other return value is ignored.

### Available hooks

- `onSkip($ctx)`
- `beforeMatch($ctx)`
- `afterMatch($ctx, $rule)`
- `onNoMatch($ctx)`
- `beforeBuildTarget($ctx, $rule)`
- `afterBuildTarget($ctx, $rule, $target): ?string`
- `beforeApplyUtms($ctx, $rule, $target, $utm): array{target?: string, utm?: array}`
- `afterApplyUtms($ctx, $rule, $finalTarget, $utm): ?string`
- `beforeSend($ctx, $rule, &$target, &$status)` — return `false` to take over the response
- `onDryRun($ctx, $rule, $target, $status)`
- `onError($ctx, \Throwable $e)`

---

## Middleware

Interface:

```
namespace rafalmasiarek\Redirector\Middleware;

use rafalmasiarek\Redirector\Context;

interface MiddlewareInterface {
  public function process(
    Context &$ctx, ?array &$rule, ?string &$target, ?int &$status, callable $next
  ): bool;
}
```

Default middleware:

```
  use rafalmasiarek\Redirector\Middleware\ServerGlobalsMiddleware;

  // Populates $ctx->ip and $ctx->ua from $_SERVER and exposes lazy headers reader.
```

Register:

```
"middleware" => [
  new \rafalmasiarek\Redirector\Middleware\ServerGlobalsMiddleware(),
],
```

---

## Safety & SEO Notes

- Prefer **301** for permanent moves (SEO), **308** to keep HTTP method (e.g., POST).
- Use **302/307** for temporary moves or campaigns.
- When measuring with UTMs, consider whether to **strip** existing UTMs or **preserve** them.
- Keep an **allowlist** of target hosts to avoid open redirects.
- Use **dry_run** in staging to verify rules.

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

```
"hooks" => [
  "afterBuildTarget" => function($ctx, $rule, &$target) {
    $p = parse_url($target);
    $path = rtrim($p["path"] ?? "", "/") . "/";
    $target = ($p["scheme"]."://".$p["host"]).$path.(isset($p["query"]) ? "?".$p["query"] : "");
  },
],
```

9) **A/B UTM content (hook)**

```
"hooks" => [
  "beforeApplyUtms" => function($ctx, $rule, &$target, &$utm) {
    if (($_COOKIE["ab"] ?? "A") === "B") {
      $utm["utm_content"] = "variant-b";
    }
  },
],
```

10) **Time-based status (middleware)**

```
new class implements \UniversalRedirector\Middleware\MiddlewareInterface {
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

## License

MIT
