<?php

namespace EngageHub\Http;

use EngageHub\Exceptions\NetworkException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Adapter for hosts that want requests to flow through their own PSR-18
 * client (Guzzle, Symfony HttpClient, ...). Requires psr/http-client and
 * psr/http-factory implementations — this class is only loaded when used.
 */
final class Psr18Transport implements Transport
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {}

    public function send(string $method, string $url, array $headers, ?string $body): Response
    {
        $request = $this->requestFactory->createRequest($method, $url);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== null) {
            $request = $request->withBody($this->streamFactory->createStream($body));
        }

        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new NetworkException("HTTP request to {$url} failed: {$e->getMessage()}", previous: $e);
        }

        $responseHeaders = [];
        foreach (array_keys($response->getHeaders()) as $name) {
            $responseHeaders[strtolower($name)] = $response->getHeaderLine($name);
        }

        return new Response($response->getStatusCode(), $responseHeaders, (string) $response->getBody());
    }
}
