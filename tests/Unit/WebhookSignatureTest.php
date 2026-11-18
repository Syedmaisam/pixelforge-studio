<?php

namespace Tests\Unit;

use App\Pixelforge\WebhookSignature;
use PHPUnit\Framework\TestCase;

/**
 * Signature checks for incoming Pixelforge webhooks.
 */
class WebhookSignatureTest extends TestCase
{
    private const SECRET = 'whsec_test';

    private const BODY = '{"id":"evt_1","type":"ping"}';

    private const NOW = 1_800_000_000;

    public function test_a_correct_signature_is_accepted(): void
    {
        $this->assertTrue(WebhookSignature::isValid($this->header(self::NOW), self::BODY, self::SECRET, self::NOW));
    }

    public function test_a_changed_body_is_rejected(): void
    {
        $this->assertFalse(WebhookSignature::isValid($this->header(self::NOW), self::BODY.' ', self::SECRET, self::NOW));
    }

    public function test_the_wrong_secret_is_rejected(): void
    {
        $this->assertFalse(WebhookSignature::isValid($this->header(self::NOW), self::BODY, 'whsec_other', self::NOW));
    }

    public function test_a_timestamp_older_than_five_minutes_is_rejected(): void
    {
        $sentAt = self::NOW - 301;

        $this->assertFalse(WebhookSignature::isValid($this->header($sentAt), self::BODY, self::SECRET, self::NOW));
    }

    public function test_a_missing_or_garbled_header_is_rejected(): void
    {
        $this->assertFalse(WebhookSignature::isValid('', self::BODY, self::SECRET, self::NOW));
        $this->assertFalse(WebhookSignature::isValid('nonsense', self::BODY, self::SECRET, self::NOW));
    }

    private function header(int $timestamp): string
    {
        $signature = hash_hmac('sha256', $timestamp.'.'.self::BODY, self::SECRET);

        return "t={$timestamp},v1={$signature}";
    }
}
