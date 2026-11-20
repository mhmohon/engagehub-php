<?php

namespace EngageHub\Resources;

/**
 * POST /payments/sync — money a contact paid: external_id, contact
 * (['external_id' => ...] and/or ['email' => ...]), amount_cents, currency,
 * status (paid|refunded|failed), refunded_cents, paid_at, refunded_at, plan
 * (['external_id' => ...]), subscription (['external_id' => ...]), attributes,
 * updated_at. Payments are EngageHub's only source of revenue (total spent,
 * group revenue). No delete: send status refunded or failed instead.
 */
class Payments extends Records
{
    protected function path(): string
    {
        return '/payments';
    }

    /** Payments are not deleted; upsert them as refunded or failed instead. */
    public function delete(string $externalId): bool
    {
        throw new \LogicException('EngageHub payments cannot be deleted. Upsert the payment as refunded or failed.');
    }
}
