<?php

namespace EngageHub\Tests;

use EngageHub\Exceptions\NetworkException;
use EngageHub\Http\Response;
use EngageHub\Http\Transport;

/**
 * Records every request and replays a scripted queue of responses (or
 * NetworkExceptions). The last scripted response repeats forever.
 */
class FakeTransport implements Transport
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $requests = [];

    /** @var list<Response|NetworkException> */
    private array $queue = [];

    public function queue(Response|NetworkException ...$responses): self
    {
        $this->queue = [...$this->queue, ...array_values($responses)];

        return $this;
    }

    public function queueJson(int $status, array $data, array $headers = []): self
    {
        return $this->queue(new Response($status, $headers, json_encode($data, JSON_THROW_ON_ERROR)));
    }

    public function send(string $method, string $url, array $headers, ?string $body): Response
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body');

        $next = count($this->queue) > 1 ? array_shift($this->queue) : ($this->queue[0] ?? null);

        if ($next === null) {
            throw new \LogicException('FakeTransport has no scripted response.');
        }

        if ($next instanceof NetworkException) {
            throw $next;
        }

        return $next;
    }

    /**
     * @return array<string, mixed>
     */
    public function lastJson(): array
    {
        $body = end($this->requests)['body'] ?? null;

        return $body === null ? [] : json_decode($body, true);
    }
}
