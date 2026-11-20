<?php

namespace EngageHub\Webhooks;

use EngageHub\Exceptions\SignatureVerificationException;

/**
 * Verifies outbound webhooks sent by EngageHub.
 *
 * Every delivery carries:
 *   EngageHub-Timestamp: <unix seconds>
 *   EngageHub-Signature: t=<unix seconds>,v1=<hex HMAC-SHA256(secret, "<t>.<raw body>")>
 *
 * Usage:
 *   $event = WebhookSignature::constructEvent($rawBody, $_SERVER['HTTP_ENGAGEHUB_SIGNATURE'] ?? '', $secret);
 *
 * Always pass the raw request body, byte for byte — never re-encoded JSON.
 */
final class WebhookSignature
{
    public const int DEFAULT_TOLERANCE = 300;

    /**
     * Throws when the signature is missing, malformed, wrong, or the
     * timestamp is outside the tolerance window (seconds, either direction).
     */
    public static function verify(
        string $payload,
        string $signatureHeader,
        string $secret,
        int $tolerance = self::DEFAULT_TOLERANCE,
        ?int $now = null,
    ): void {
        [$timestamp, $signatures] = self::parse($signatureHeader);

        if ($timestamp === null || $signatures === []) {
            throw new SignatureVerificationException('EngageHub-Signature header is missing or malformed.');
        }

        $expected = self::compute($payload, $secret, $timestamp);
        $matched = false;

        foreach ($signatures as $candidate) {
            if (hash_equals($expected, $candidate)) {
                $matched = true;
                break;
            }
        }

        if (! $matched) {
            throw new SignatureVerificationException('EngageHub webhook signature does not match.');
        }

        $now ??= time();

        if ($tolerance > 0 && abs($now - $timestamp) > $tolerance) {
            throw new SignatureVerificationException('EngageHub webhook timestamp is outside the tolerance window.');
        }
    }

    /**
     * Boolean form of {@see verify()}.
     */
    public static function isValid(
        string $payload,
        string $signatureHeader,
        string $secret,
        int $tolerance = self::DEFAULT_TOLERANCE,
        ?int $now = null,
    ): bool {
        try {
            self::verify($payload, $signatureHeader, $secret, $tolerance, $now);

            return true;
        } catch (SignatureVerificationException) {
            return false;
        }
    }

    /**
     * Verify, then decode the JSON body.
     *
     * @return array<string, mixed>
     */
    public static function constructEvent(
        string $payload,
        string $signatureHeader,
        string $secret,
        int $tolerance = self::DEFAULT_TOLERANCE,
    ): array {
        self::verify($payload, $signatureHeader, $secret, $tolerance);

        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            throw new SignatureVerificationException('EngageHub webhook body is not a JSON object.');
        }

        return $decoded;
    }

    /** The v1 signature EngageHub computes for a body at a timestamp. */
    public static function compute(string $payload, string $secret, int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
    }

    /** Build a header value (useful for testing your own receiver). */
    public static function header(string $payload, string $secret, int $timestamp): string
    {
        return 't='.$timestamp.',v1='.self::compute($payload, $secret, $timestamp);
    }

    /**
     * @return array{0: int|null, 1: list<string>}
     */
    private static function parse(string $header): array
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = strtolower($value);
            }
        }

        return [$timestamp, $signatures];
    }
}
