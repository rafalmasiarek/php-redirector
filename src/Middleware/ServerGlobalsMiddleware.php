<?php

declare(strict_types=1);

namespace rafalmasiarek\Redirector\Middleware;

use rafalmasiarek\Redirector\Context;

/**
 * Class ServerGlobalsMiddleware
 *
 * Default middleware enriching the Context with data from $_SERVER.
 * Specifically, sets $ctx->ip and $ctx->ua if not already provided and exposes a lazy headers reader.
 *
 * @package rafalmasiarek\Redirector\Middleware
 */
class ServerGlobalsMiddleware implements MiddlewareInterface
{
    /**
     * @inheritDoc
     */
    public function process(Context &$ctx, ?array &$rule, ?string &$target, ?int &$status, callable $next): bool
    {
        // Fill IP/UA exactly from $_SERVER if absent.
        if ($ctx->ip === null && isset($_SERVER['REMOTE_ADDR'])) {
            $ctx->ip = $_SERVER['REMOTE_ADDR'];
        }
        if ($ctx->ua === null && isset($_SERVER['HTTP_USER_AGENT'])) {
            $ctx->ua = $_SERVER['HTTP_USER_AGENT'];
        }

        // Provide a lazy headers reader if not set.
        if ($ctx->headers === null) {
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
        }

        return $next($ctx, $rule, $target, $status);
    }
}
