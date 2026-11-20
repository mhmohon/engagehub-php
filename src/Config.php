<?php

namespace EngageHub;

/**
 * Immutable client configuration. baseUrl points at the versioned API root,
 * e.g. https://engagehub.example.com/api/v1 — paths are joined onto it.
 *
 * group is only needed for a key that is not tied to a group: it is sent as
 * "group" with every request. userAgent tells EngageHub which SDK sent an
 * event (wrappers such as engagehub/laravel pass their own name).
 *
 * commandUrl is where EngageHub can reach this app with a signed command
 * ("Sync existing data" runs at once instead of on the next poll). It is sent
 * as the EngageHub-Command-Url header; null keeps the app poll-only.
 */
final readonly class Config
{
    public string $baseUrl;

    public function __construct(
        public string $apiKey,
        string $baseUrl,
        public int $timeout = 10,
        public int $maxRetries = 3,
        public int $retryBaseMs = 500,
        public ?string $group = null,
        public string $userAgent = 'engagehub-php/'.EngageHubClient::VERSION,
        public ?string $commandUrl = null,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }
}
