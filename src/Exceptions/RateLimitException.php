<?php

namespace EngageHub\Exceptions;

/** 429 — thrown only after the client's own retries are exhausted. */
class RateLimitException extends EngageHubException
{
    public function __construct(string $message, private readonly ?int $retryAfter = null)
    {
        parent::__construct($message);
    }

    /** Seconds to wait before retrying, when the server said so. */
    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
