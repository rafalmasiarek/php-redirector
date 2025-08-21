<?php

declare(strict_types=1);

namespace rafalmasiarek\Redirector\Middleware;

use rafalmasiarek\Redirector\Context;

/**
 * Interface MiddlewareInterface
 *
 * PSR-inspired middleware contract for the redirect pipeline.
 * Middlewares receive the current Context and may inspect/modify the matched rule, target URL and status code.
 *
 * If a middleware wants to short-circuit (e.g. send a custom response), it must return FALSE.
 * Otherwise it should invoke $next($ctx, $rule, $target, $status) and return its result.
 *
 * @package rafalmasiarek\Redirector\Middleware
 */
interface MiddlewareInterface
{
    /**
     * Process the redirect pipeline.
     *
     * @param Context $ctx       Current request context.
     * @param array|null $rule   Matched rule (mutable), or null when no rule matched yet.
     * @param string|null $target Target URL (mutable), may be null before building.
     * @param int|null $status   HTTP status (mutable), may be null before decided.
     * @param callable $next     Next middleware in chain: function (Context &$ctx, ?array &$rule, ?string &$target, ?int &$status): bool
     * @return bool              TRUE to continue pipeline; FALSE if the middleware already produced a response.
     */
    public function process(Context &$ctx, ?array &$rule, ?string &$target, ?int &$status, callable $next): bool;
}
