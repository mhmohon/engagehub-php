<?php

namespace EngageHub\Resources;

use DateTimeInterface;
use EngageHub\Dto\IngestResult;
use EngageHub\Dto\ItemResult;
use EngageHub\EngageHubClient;
use EngageHub\Support\Uuid;

/**
 * POST /events. event_id is filled in before the first send attempt so
 * client-side retries are idempotent.
 */
class Events
{
    private const MAX_BATCH = 500;

    public function __construct(private readonly EngageHubClient $client) {}

    /**
     * Track a single event. The person is optional (anonymous events are
     * kept): pass externalId (your user id) and/or email.
     *
     * @param  array<string, mixed>  $properties
     */
    public function track(
        string $name,
        ?string $email = null,
        ?string $externalId = null,
        array $properties = [],
        DateTimeInterface|string|null $occurredAt = null,
        ?string $eventId = null,
    ): ItemResult {
        $event = array_filter([
            'name' => $name,
            'email' => $email,
            'external_id' => $externalId,
            'properties' => $properties ?: null,
            'occurred_at' => $occurredAt instanceof DateTimeInterface
                ? $occurredAt->format(DateTimeInterface::ATOM)
                : $occurredAt,
            'event_id' => $eventId,
        ], static fn (mixed $v): bool => $v !== null);

        $event = self::withEventId($event);

        $result = $this->trackBatch([$event]);

        if (isset($result->results[0])) {
            return $result->results[0];
        }

        $status = match (true) {
            $result->accepted > 0 => 'accepted',
            $result->duplicates > 0 => 'duplicate',
            default => 'rejected',
        };

        return new ItemResult(
            index: 0,
            status: $status,
            identity: $email ?? $externalId,
            eventId: $event['event_id'],
            name: $name,
        );
    }

    /**
     * Track many events (wire-format arrays: name, email, external_id,
     * properties, occurred_at, event_id). Chunks batches above the server's 500-item limit and
     * merges the results.
     *
     * @param  list<array<string, mixed>>  $events
     */
    public function trackBatch(array $events): IngestResult
    {
        $events = array_map(self::withEventId(...), array_values($events));

        $result = new IngestResult(0, 0, 0);

        foreach (array_chunk($events, self::MAX_BATCH) as $chunk) {
            $response = count($chunk) === 1
                ? $this->client->request('POST', '/events', $chunk[0])
                : $this->client->request('POST', '/events', ['batch' => $chunk]);

            $result = $result->merge(IngestResult::fromResponse(
                $response + ['results' => $response['results'] ?? $this->syntheticResults($chunk, $response)],
            ));
        }

        return $result;
    }

    /**
     * The server omits per-item results for a fully-accepted single event;
     * synthesize them so callers always get one entry per item.
     *
     * @param  list<array<string, mixed>>  $chunk
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
     */
    private function syntheticResults(array $chunk, array $response): array
    {
        if ((int) ($response['accepted'] ?? 0) !== count($chunk)) {
            return [];
        }

        return array_map(
            static fn (array $event, int $index): array => [
                'index' => $index,
                'status' => 'accepted',
                'event_id' => $event['event_id'],
                'name' => $event['name'] ?? null,
                'identity' => $event['email'] ?? $event['external_id'] ?? null,
            ],
            $chunk,
            array_keys($chunk),
        );
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private static function withEventId(array $event): array
    {
        $event['event_id'] ??= Uuid::v4();

        return $event;
    }
}
