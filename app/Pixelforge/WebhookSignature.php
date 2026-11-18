<?php

namespace App\Pixelforge;

/**
 * Checks the Pixelforge-Signature header: "t=<unix time>,v1=<hex signature>".
 *
 * The signature is HMAC-SHA256 of "<t>.<raw body>", keyed with the webhook
 * signing secret (whsec_...). We also reject old timestamps so a captured
 * request cannot be replayed later.
 */
final class WebhookSignature
{
    public const TOLERANCE_SECONDS = 300;

    public static function isValid(string $header, string $rawBody, string $secret, int $now): bool
    {
        $parts = self::parseHeader($header);

        $timestamp = (int) ($parts['t'] ?? 0);
        $received = $parts['v1'] ?? '';

        if ($timestamp === 0 || $received === '' || $secret === '') {
            return false;
        }

        if (abs($now - $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret);

        return hash_equals($expected, $received); // constant-time comparison
    }

    /**
     * "t=123,v1=abc" => ['t' => '123', 'v1' => 'abc']
     *
     * @return array<string, string>
     */
    private static function parseHeader(string $header): array
    {
        $parts = [];

        foreach (explode(',', $header) as $piece) {
            [$key, $value] = array_pad(explode('=', trim($piece), 2), 2, '');
            $parts[$key] = $value;
        }

        return $parts;
    }
}
