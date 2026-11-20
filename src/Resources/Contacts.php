<?php

namespace EngageHub\Resources;

use EngageHub\Dto\ItemResult;
use EngageHub\Dto\SyncResult;
use EngageHub\EngageHubClient;
use EngageHub\Exceptions\NotFoundException;

/**
 * /contacts — create-or-update keyed by external_id (then email), read back,
 * delete. Upserts are idempotent by nature, so retries need no idempotency key.
 */
class Contacts
{
    private const MAX_BATCH = 500;

    public function __construct(private readonly EngageHubClient $client) {}

    /**
     * Upsert one contact (wire-format array: external_id, email, first_name,
     * last_name, phone, country, attributes, tags, consent, created_at,
     * updated_at). Only keys present are written; null clears a field.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function upsert(array $attributes): ItemResult
    {
        $response = $this->client->request('POST', '/contacts/sync', $attributes);

        return SyncResult::fromResponse($response)->results[0]
            ?? new ItemResult(0, 'rejected', $attributes['external_id'] ?? $attributes['email'] ?? null);
    }

    /**
     * Upsert many contacts. Chunks batches above the server's 500-item
     * limit and merges the results. With $backfill, EngageHub records no
     * change events (contact_created...) for these.
     *
     * @param  list<array<string, mixed>>  $contacts
     */
    public function upsertBatch(array $contacts, bool $backfill = false): SyncResult
    {
        $result = new SyncResult(0, 0, 0, 0);

        foreach (array_chunk(array_values($contacts), self::MAX_BATCH) as $chunk) {
            $result = $result->merge(SyncResult::fromResponse(
                $this->client->request('POST', '/contacts/sync', ['batch' => $chunk] + ($backfill ? ['mode' => 'backfill'] : [])),
            ));
        }

        return $result;
    }

    /**
     * Status, consent and attributes of one of your users, or null when
     * EngageHub does not know them.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $externalId): ?array
    {
        try {
            return $this->client->request('GET', '/contacts/'.rawurlencode($externalId));
        } catch (NotFoundException) {
            return null;
        }
    }

    /**
     * Your app deleted this user: they leave the group in EngageHub. Returns
     * false when EngageHub never knew them.
     */
    public function delete(string $externalId): bool
    {
        try {
            $this->client->request('DELETE', '/contacts/'.rawurlencode($externalId));

            return true;
        } catch (NotFoundException) {
            return false;
        }
    }
}
