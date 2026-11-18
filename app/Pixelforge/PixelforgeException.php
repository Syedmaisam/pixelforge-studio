<?php

namespace App\Pixelforge;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * An error response from Pixelforge, e.g.
 * { "error": { "type": "rate_limit_error", "code": "rate_limit_exceeded", "message": "..." } }
 *
 * We branch on $errorCode (as the docs recommend), never on the message text.
 */
class PixelforgeException extends RuntimeException
{
    /** Errors where waiting and trying again can work. Nothing was created on Pixelforge's side. */
    private const RETRYABLE_CODES = [
        'rate_limit_exceeded',
        'concurrency_limit_exceeded',
        'overloaded',
    ];

    /** Errors that mean OUR setup or code is wrong. Users can't fix them, so we log them. */
    private const OUR_FAULT_CODES = [
        'invalid_api_key',
        'idempotency_key_reused',
    ];

    public function __construct(
        public readonly int $httpStatus,
        public readonly string $errorCode,
        string $message,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message);
    }

    public static function fromResponse(Response $response): self
    {
        $retryAfter = $response->header('Retry-After');
        $errorCode = $response->json('error.code');
        $message = $response->json('error.message');

        return new self(
            httpStatus: $response->status(),
            errorCode: is_string($errorCode) ? $errorCode : 'unknown_error',
            message: is_string($message) ? $message : "Pixelforge returned HTTP {$response->status()}.",
            retryAfterSeconds: is_numeric($retryAfter) ? (int) $retryAfter : null,
        );
    }

    public function isRetryable(): bool
    {
        // daily_limit_exceeded is also a 429, but it is NOT in the list:
        // retrying today can't fix it. Unknown 5xx errors are retried; the
        // Idempotency-Key guarantees a retry never creates a second generation.
        return in_array($this->errorCode, self::RETRYABLE_CODES, true)
            || $this->httpStatus >= 500;
    }

    public function isOurFault(): bool
    {
        return in_array($this->errorCode, self::OUR_FAULT_CODES, true);
    }

    /** A short, plain-English explanation we can show the user. */
    public function messageForUser(): string
    {
        return match (true) {
            $this->errorCode === 'content_policy_violation' => 'Pixelforge rejected this prompt. Try rewording it.',
            $this->errorCode === 'daily_limit_exceeded' => 'The daily generation limit has been reached. Please try again tomorrow (UTC).',
            $this->errorCode === 'validation_failed' => 'Pixelforge rejected the request: '.$this->getMessage(),
            $this->isOurFault() => 'Image generation is unavailable right now because of a problem on our side.',
            $this->isRetryable() => 'Pixelforge was too busy to accept this generation.',
            default => 'Pixelforge could not accept this generation ('.$this->errorCode.').',
        };
    }
}
