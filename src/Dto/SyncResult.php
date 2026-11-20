<?php

namespace EngageHub\Dto;

/**
 * Outcome of POST /contacts/sync. accepted = created + updated + unchanged;
 * stale items (older than what EngageHub has) are counted apart. Per-item
 * rejections do not throw.
 */
final readonly class SyncResult
{
    /**
     * @param  list<ItemResult>  $results
     */
    public function __construct(
        public int $accepted,
        public int $created,
        public int $updated,
        public int $rejected,
        public array $results = [],
        public int $unchanged = 0,
        public int $stale = 0,
    ) {}

    /**
     * @param  array<string, mixed>  $response
     */
    public static function fromResponse(array $response): self
    {
        return new self(
            accepted: (int) ($response['accepted'] ?? 0),
            created: (int) ($response['created'] ?? 0),
            updated: (int) ($response['updated'] ?? 0),
            rejected: (int) ($response['rejected'] ?? 0),
            results: array_map(ItemResult::fromArray(...), $response['results'] ?? []),
            unchanged: (int) ($response['unchanged'] ?? 0),
            stale: (int) ($response['stale'] ?? 0),
        );
    }

    public function merge(self $other): self
    {
        $offset = count($this->results);

        return new self(
            accepted: $this->accepted + $other->accepted,
            created: $this->created + $other->created,
            updated: $this->updated + $other->updated,
            rejected: $this->rejected + $other->rejected,
            results: [
                ...$this->results,
                ...array_map(
                    static fn (ItemResult $r): ItemResult => new ItemResult(
                        $r->index + $offset, $r->status, $r->identity, $r->contactId, $r->eventId, $r->name, $r->errors,
                    ),
                    $other->results,
                ),
            ],
            unchanged: $this->unchanged + $other->unchanged,
            stale: $this->stale + $other->stale,
        );
    }

    /**
     * @return list<ItemResult>
     */
    public function rejectedItems(): array
    {
        return array_values(array_filter($this->results, static fn (ItemResult $r): bool => $r->rejected()));
    }
}
