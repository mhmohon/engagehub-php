<?php

namespace EngageHub\Tests;

use EngageHub\Exceptions\SignatureVerificationException;
use EngageHub\Webhooks\WebhookSignature;
use PHPUnit\Framework\TestCase;

class WebhookSignatureTest extends TestCase
{
    private const string SECRET = 'whsec_test';

    private const string BODY = '{"type":"event","event":{"name":"signup_completed"}}';

    public function test_valid_signature_within_tolerance_passes(): void
    {
        $t = 1_700_000_000;
        $header = 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.self::BODY, self::SECRET);

        WebhookSignature::verify(self::BODY, $header, self::SECRET, now: $t + 10);
        $this->assertTrue(WebhookSignature::isValid(self::BODY, $header, self::SECRET, now: $t + 299));
    }

    public function test_tampered_body_is_rejected(): void
    {
        $t = 1_700_000_000;
        $header = WebhookSignature::header(self::BODY, self::SECRET, $t);

        $this->expectException(SignatureVerificationException::class);
        WebhookSignature::verify(self::BODY.' ', $header, self::SECRET, now: $t);
    }

    public function test_wrong_secret_is_rejected(): void
    {
        $t = 1_700_000_000;
        $header = WebhookSignature::header(self::BODY, 'whsec_other', $t);

        $this->assertFalse(WebhookSignature::isValid(self::BODY, $header, self::SECRET, now: $t));
    }

    public function test_stale_or_future_timestamp_is_rejected(): void
    {
        $t = 1_700_000_000;
        $header = WebhookSignature::header(self::BODY, self::SECRET, $t);

        $this->assertFalse(WebhookSignature::isValid(self::BODY, $header, self::SECRET, now: $t + 301));
        $this->assertFalse(WebhookSignature::isValid(self::BODY, $header, self::SECRET, now: $t - 301));
        $this->assertTrue(WebhookSignature::isValid(self::BODY, $header, self::SECRET, tolerance: 600, now: $t + 500));
    }

    public function test_replayed_signature_with_swapped_timestamp_fails(): void
    {
        $t = 1_700_000_000;
        $sig = WebhookSignature::compute(self::BODY, self::SECRET, $t);

        // Attacker refreshes t but cannot re-sign.
        $this->assertFalse(WebhookSignature::isValid(self::BODY, 't='.($t + 1000).',v1='.$sig, self::SECRET, now: $t + 1000));
    }

    public function test_malformed_header_is_rejected(): void
    {
        $this->assertFalse(WebhookSignature::isValid(self::BODY, '', self::SECRET));
        $this->assertFalse(WebhookSignature::isValid(self::BODY, 'v1=abc', self::SECRET));
        $this->assertFalse(WebhookSignature::isValid(self::BODY, 't=abc,v1=def', self::SECRET));
    }

    public function test_construct_event_decodes_body(): void
    {
        $header = WebhookSignature::header(self::BODY, self::SECRET, time());

        $event = WebhookSignature::constructEvent(self::BODY, $header, self::SECRET);

        $this->assertSame('signup_completed', $event['event']['name']);
    }
}
