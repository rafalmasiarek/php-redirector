<?php

declare(strict_types=1);

namespace rafalmasiarek\Redirector;

/**
 * Class Context
 *
 * Lightweight request context passed to hooks and middleware.
 * Stores request method, URL parts, and selected server-provided metadata.
 *
 * @package rafalmasiarek\Redirector
 */
class Context
{
    /** @var string HTTP method (e.g. GET, POST) */
    public string $method = 'GET';

    /** @var string Full requested URL */
    public string $url = '';

    /** @var string URL scheme (http|https) */
    public string $scheme = 'http';

    /** @var string Host name */
    public string $host = 'localhost';

    /** @var int|null Port (nullable if default) */
    public ?int $port = null;

    /** @var string Request path (leading slash) */
    public string $path = '/';

    /** @var array Parsed query parameters */
    public array $query = [];

    /** @var string|null URL fragment (without '#') */
    public ?string $fragment = null;

    /** @var string|null Client IP address */
    public ?string $ip = null;

    /** @var string|null User-Agent string */
    public ?string $ua = null;

    /**
     * Headers accessor; because not all SAPIs expose headers uniformly,
     * this can be a callable for lazy fetching.
     *
     * @var callable|null function (): array<string,string>
     */
    public $headers = null;

    /** @var array Arbitrary metadata bag */
    public array $meta = [];
}
