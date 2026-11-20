<?php

namespace EngageHub\Support;

/**
 * RFC 4122 v4 from random_bytes — enough for idempotency keys without a
 * ramsey/uuid dependency.
 */
final class Uuid
{
    public static function v4(): string
    {
        $bytes = random_bytes(16);

        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
