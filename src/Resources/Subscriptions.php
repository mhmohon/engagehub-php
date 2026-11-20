<?php

namespace EngageHub\Resources;

/**
 * /subscriptions — a user on a plan: external_id, contact (['external_id' => ...]
 * and/or ['email' => ...]), plan (['external_id' => ..., 'name' => ...]), status
 * (trialing|active|past_due|paused|canceled|ended), dates, amount_cents, currency,
 * interval, updated_at. Status changes become events in EngageHub.
 */
class Subscriptions extends Records
{
    protected function path(): string
    {
        return '/subscriptions';
    }
}
