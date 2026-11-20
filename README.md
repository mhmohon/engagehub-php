# engagehub/engagehub-php

Framework-agnostic PHP SDK for [EngageHub](../../apps/engagehub/README.md): push events and sync contacts into an EngageHub workspace from any PHP app (Symfony, WordPress, vanilla PHP, ...). Laravel apps should use [`engagehub/laravel`](../engagehub-laravel) instead, which builds on this package.

> **Status (Nov 2026):** speaks EngageHub's REST API v1 (phase 1a: contacts, events, ping). Contract: [apps/engagehub/docs/data-sync/06-rest-api-and-webhooks.md](../../apps/engagehub/docs/data-sync/06-rest-api-and-webhooks.md). Not on Packagist or npm yet: install from this repository. Plans, subscriptions and objects come in phase 1b.

Zero runtime dependencies — a built-in cURL transport is used unless you inject your own PSR-18 client.

## Install

```jsonc
// composer.json of the consuming app
"repositories": [
  { "type": "path", "url": "/path/to/engagehub/packages/engagehub-php", "options": { "symlink": true } }
],
"require": { "engagehub/engagehub-php": "@dev" }
```

## Usage

```php
use EngageHub\Config;
use EngageHub\EngageHubClient;

$engagehub = new EngageHubClient(new Config(
    apiKey: 'eh_live_...',                          // from EngageHub: Integrations → Connect an app
    baseUrl: 'https://your-engagehub-host/api/v1',
    // group: 'ebookwriter',                        // only for a key not tied to a group
));

// Verify the credential (group, connection, scopes)
$engagehub->ping();

// Upsert contacts (keyed by your external_id, then email). Only keys present are
// written; null clears a field. Consent can only be turned off; tags are only added.
$engagehub->contacts()->upsert([
    'external_id' => 'user-1',
    'email' => 'jane@example.com',
    'first_name' => 'Jane',
    'attributes' => ['role' => 'admin', 'books_count' => 3],
    'updated_at' => '2026-11-17T09:00:00Z',        // older updates arriving late are skipped as stale
]);
$result = $engagehub->contacts()->upsertBatch($manyContacts); // chunks > 500 automatically
$result->created; $result->updated; $result->unchanged; $result->stale; $result->rejectedItems();

// Track events. event_id is auto-generated before the first attempt, so retries are idempotent.
// Send the email too when the user may not be synced yet: EngageHub then creates the contact.
$engagehub->events()->track('book_published', externalId: 'user-1', properties: ['book_id' => 8841]);
$engagehub->events()->trackBatch([
    ['name' => 'page_viewed', 'external_id' => 'user-1', 'properties' => ['path' => '/pricing']],
]);

// Read back (status, consent, attributes) and delete
$engagehub->contacts()->get('user-1');     // array, or null when EngageHub does not know them
$engagehub->contacts()->delete('user-1');  // they leave the group; false when unknown
```

Buffering many events in one process:

```php
$buffer = new \EngageHub\Buffer\EventBuffer($engagehub->events());
$buffer->add(['name' => 'step_completed', 'external_id' => 'user-1']);
$buffer->flush(); // auto-flushes at 500 too
```

Declaring meters (things people use up, from contact attributes you send; skip if your app has none):

```php
$engagehub->describe(['meters' => [
    ['used' => 'credits_used', 'limit' => 'credits', 'label' => 'Credits'],
    ['used' => 'exports'], // no cap
]]);
```

## Verifying EngageHub webhooks

EngageHub signs every outbound webhook with `EngageHub-Signature: t=<unix>,v1=<hex>`,
where `v1 = HMAC-SHA256(secret, "<t>.<raw body>")`. Verify against the raw body
and reject stale timestamps (default tolerance 300 s):

```php
use EngageHub\Webhooks\WebhookSignature;
use EngageHub\Exceptions\SignatureVerificationException;

try {
    $event = WebhookSignature::constructEvent(
        file_get_contents('php://input'),
        $_SERVER['HTTP_ENGAGEHUB_SIGNATURE'] ?? '',
        getenv('ENGAGEHUB_WEBHOOK_SECRET'),
    );
} catch (SignatureVerificationException) {
    http_response_code(400);
    exit;
}
```

## Errors & retries

429 / 5xx / network failures retry automatically (exponential backoff + jitter, honors `Retry-After`). After retries, calls throw typed exceptions: `AuthenticationException` (401), `AuthorizationException` (403), `NotFoundException` (404), `ValidationException` (422, `->errors()`), `RateLimitException` (429, `->retryAfter()`), `ServerException` (5xx), `NetworkException`. Per-item rejections (`invalid`, `identity_conflict`, `email_taken`, `unknown_contact`) return in the result DTOs instead of throwing. A paused connection answers 423 (`EngageHubException`).

Inject your own HTTP stack:

```php
$engagehub = new EngageHubClient($config, new \EngageHub\Http\Psr18Transport($psr18Client, $requestFactory, $streamFactory));
```

## Tests

```bash
composer install && composer test
```
