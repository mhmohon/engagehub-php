<?php

namespace EngageHub\Dto;

/**
 * Outcome of POST /events. Per-item business rejections live here — they do
 * not throw, so batch callers can act on partial failure.
 */
final readonly class IngestResult
{
    /**
     * @param  list<ItemResult>  $results
     */
    public function __construct(
        public int $accepted,
        public int $duplicates,
        public int $rejected,
        public array $results = [],
    ) {}

    /**
     * @param  array<string, mixed>  $response
     */
    public static function fromResponse(array $response): self
    {
        return new self(
            accepted: (int) ($response['accepted'] ?? 0),
            duplicates: (int) ($response['duplicates'] ?? 0),
            rejected: (int) ($response['rejected'] ?? 0),
            results: array_map(ItemResult::fromArray(...), $response['results'] ?? []),
        );
    }

    public function merge(self $other): self
    {
        $offset = count($this->results);

        return new self(
            accepted: $this->accepted + $other->accepted,
            duplicates: $this->duplicates + $other->duplicates,
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
