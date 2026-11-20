<?php

namespace EngageHub\Resources;

use EngageHub\Dto\ItemResult;
use EngageHub\Dto\SyncResult;
use EngageHub\EngageHubClient;
use EngageHub\Exceptions\NotFoundException;

/**
 * Shared upsert/delete plumbing for /plans, /subscriptions and /objects/{type}.
 * Upserts are idempotent; `updated_at` lets EngageHub skip late, older updates.
 */
abstract class Records
{
    private const MAX_BATCH = 500;

    public function __construct(protected readonly EngageHubClient $client) {}

    /** The collection path, e.g. "/subscriptions". */
    abstract protected function path(): string;

    /**
     * @param  array<string, mixed>  $record
     */
    public function upsert(array $record): ItemResult
    {
        $response = $this->client->request('POST', $this->path().'/sync', $record);

        return SyncResult::fromResponse($response)->results[0]
            ?? new ItemResult(0, 'rejected', isset($record['external_id']) ? (string) $record['external_id'] : null);
    }

    /**
     * Upsert many records, in chunks of 500. With $backfill, EngageHub stores them
     * without recording change events (loading existing data must not start journeys).
     *
     * @param  list<array<string, mixed>>  $records
     */
    public function upsertBatch(array $records, bool $backfill = false): SyncResult
    {
        $result = new SyncResult(0, 0, 0, 0);

        foreach (array_chunk(array_values($records), self::MAX_BATCH) as $chunk) {
            $body = ['batch' => $chunk] + ($backfill ? ['mode' => 'backfill'] : []);
            $result = $result->merge(SyncResult::fromResponse($this->client->request('POST', $this->path().'/sync', $body)));
        }

        return $result;
    }

    /** The record was deleted in your app. False when EngageHub never knew it. */
    public function delete(string $externalId): bool
    {
        return $this->deleteById($externalId);
    }

    protected function deleteById(string $externalId): bool
    {
        try {
            $this->client->request('DELETE', $this->path().'/'.rawurlencode($externalId));

            return true;
        } catch (NotFoundException) {
            return false;
        }
    }
}
