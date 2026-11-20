<?php

namespace EngageHub\Resources;

use EngageHub\EngageHubClient;
use InvalidArgumentException;

/**
 * /objects/{type} — anything else a user owns in your app: books, sites, orders
 * (external_id, contact, name, attributes, created_at, updated_at).
 */
class Objects extends Records
{
    public function __construct(EngageHubClient $client, private readonly string $type)
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,47}$/', $type) !== 1) {
            throw new InvalidArgumentException("Object type \"{$type}\" must be lowercase letters, digits and _ (for example \"book\").");
        }

        parent::__construct($client);
    }

    protected function path(): string
    {
        return '/objects/'.$this->type;
    }
}
