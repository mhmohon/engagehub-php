<?php

namespace EngageHub\Http;

use EngageHub\Exceptions\NetworkException;

/**
 * The single seam between the SDK and HTTP. Implementations return a
 * Response for every completed HTTP exchange (including 4xx/5xx) and throw
 * NetworkException only when no response was obtained at all.
 */
interface Transport
{
    /**
     * @param  array<string, string>  $headers
     *
     * @throws NetworkException
     */
    public function send(string $method, string $url, array $headers, ?string $body): Response;
}
