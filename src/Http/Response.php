<?php

namespace EngageHub\Http;

/**
 * Minimal transport-level response: status, lower-cased headers, raw body.
 */
final readonly class Response
{
    /**
     * @param  array<string, string>  $headers  keys lower-cased
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function json(): array
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : [];
    }
}
