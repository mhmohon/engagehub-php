<?php

namespace EngageHub\Dto;

/**
 * One item's outcome inside an ingest or sync response. status is one of
 * accepted|duplicate|created|updated|unchanged|stale|rejected.
 */
final readonly class ItemResult
{
    /**
     * @param  array<string, mixed>  $errors
     */
    public function __construct(
        public int $index,
        public string $status,
        public ?string $identity = null,
        public ?int $contactId = null,
        public ?string $eventId = null,
        public ?string $name = null,
        public array $errors = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            index: (int) ($data['index'] ?? 0),
            status: (string) ($data['status'] ?? 'accepted'),
            identity: isset($data['external_id']) ? (string) $data['external_id'] : ($data['email'] ?? null),
            contactId: isset($data['contact_id']) ? (int) $data['contact_id'] : null,
            eventId: $data['event_id'] ?? null,
            name: $data['name'] ?? null,
            errors: (array) ($data['errors'] ?? []),
        );
    }

    public function accepted(): bool
    {
        return in_array($this->status, ['accepted', 'created', 'updated', 'unchanged'], true);
    }

    public function rejected(): bool
    {
        return $this->status === 'rejected';
    }

    /** Skipped because EngageHub already had a newer version (by updated_at). */
    public function stale(): bool
    {
        return $this->status === 'stale';
    }
}
