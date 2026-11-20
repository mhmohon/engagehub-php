<?php

namespace EngageHub\Buffer;

use EngageHub\Dto\IngestResult;
use EngageHub\Resources\Events;

/**
 * In-memory event buffer: add() events as they happen, flush() sends them
 * as one batch. Auto-flushes when the buffer reaches the server's batch
 * cap. Optionally flushes on destruct (swallowing errors — destructors
 * must not throw).
 */
class EventBuffer
{
    private const AUTO_FLUSH_AT = 500;

    /** @var list<array<string, mixed>> */
    private array $events = [];

    public function __construct(
        private readonly Events $api,
        private readonly bool $flushOnDestruct = false,
    ) {}

    /**
     * @param  array<string, mixed>  $event  wire-format: name, email, external_id, payload, ...
     */
    public function add(array $event): ?IngestResult
    {
        $this->events[] = $event;

        return count($this->events) >= self::AUTO_FLUSH_AT ? $this->flush() : null;
    }

    public function flush(): ?IngestResult
    {
        if ($this->events === []) {
            return null;
        }

        $batch = $this->events;
        $this->events = [];

        return $this->api->trackBatch($batch);
    }

    public function count(): int
    {
        return count($this->events);
    }

    public function __destruct()
    {
        if (! $this->flushOnDestruct) {
            return;
        }

        try {
            $this->flush();
        } catch (\Throwable) {
            // Destructors must not throw; unsent events are lost by design here.
        }
    }
}
