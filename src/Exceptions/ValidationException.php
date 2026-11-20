<?php

namespace EngageHub\Exceptions;

/** 422 — the request itself was malformed (per-item business rejections do NOT throw). */
class ValidationException extends EngageHubException
{
    /**
     * @param  array<string, mixed>  $errors  field => messages, as returned by the API
     */
    public function __construct(string $message, private readonly array $errors = [])
    {
        parent::__construct($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
