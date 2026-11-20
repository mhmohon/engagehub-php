<?php

namespace EngageHub\Resources;

/**
 * POST /plans/sync — the plans your product sells (external_id, name,
 * amount_cents, currency, interval, trial_days, active, attributes).
 * No delete: send `'active' => false` for a retired plan.
 */
class Plans extends Records
{
    protected function path(): string
    {
        return '/plans';
    }

    /** Plans are not deleted; upsert ['external_id' => ..., 'active' => false] instead. */
    public function delete(string $externalId): bool
    {
        throw new \LogicException('EngageHub plans cannot be deleted. Upsert the plan with active = false.');
    }
}
