<?php

namespace App\Pixelforge;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * A thin wrapper around the Pixelforge HTTP API.
 *
 * It only sends requests, logs each one, and turns error responses into
 * PixelforgeException. It makes no decisions about retries or money; the callers do that.
 *
 * Every request is written to the Laravel log ("Pixelforge API request") with its
 * method, path, status, duration and Pixelforge error code. The API key, prompts and
 * response bodies are never logged.
 */
class PixelforgeClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
    ) {}

    /**
     * POST /v1/generations
     *
     * The Idempotency-Key makes retries safe: sending the same key and body again
     * returns the SAME generation instead of creating (and billing) a new one.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed> The generation object.
     *
     * @throws PixelforgeException|ConnectionException
     */
    public function createGeneration(array $params, string $idempotencyKey): array
    {
        return $this->send(
            'POST',
            '/generations',
            fn (PendingRequest $http): Response => $http
                ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                ->post('/generations', $params),
            ['idempotency_key' => $idempotencyKey],
        );
    }

    /**
     * GET /v1/generations/{id}, the polling fallback for missed webhooks.
     *
     * @return array<string, mixed> The generation object.
     *
     * @throws PixelforgeException|ConnectionException
     */
    public function getGeneration(string $pixelforgeId): array
    {
        $path = "/generations/{$pixelforgeId}";

        return $this->send('GET', $path, fn (PendingRequest $http): Response => $http->get($path));
    }

    /**
     * GET /v1/pricing (no auth needed, but sending the key does no harm).
     *
     * @return array<string, mixed>
     *
     * @throws PixelforgeException|ConnectionException
     */
    public function pricing(): array
    {
        return $this->send('GET', '/pricing', fn (PendingRequest $http): Response => $http->get('/pricing'));
    }

    /**
     * GET /v1/usage: what Pixelforge billed THIS API key, per UTC day.
     *
     * @return array<string, mixed>
     *
     * @throws PixelforgeException|ConnectionException
     */
    public function usage(string $startDate, string $endDate): array
    {
        $query = ['start_date' => $startDate, 'end_date' => $endDate];

        return $this->send(
            'GET',
            '/usage',
            fn (PendingRequest $http): Response => $http->get('/usage', $query),
            $query,
        );
    }

    /**
     * Send one request, log it, and return the decoded JSON body.
     *
     * @param  Closure(PendingRequest): Response  $request
     * @param  array<string, string>  $context  Extra, non-secret details for the log line.
     * @return array<string, mixed>
     *
     * @throws PixelforgeException|ConnectionException
     */
    private function send(string $method, string $path, Closure $request, array $context = []): array
    {
        $startedAt = microtime(true);
        $logContext = ['method' => $method, 'path' => $path, ...$context];

        try {
            $response = $request($this->http());
        } catch (ConnectionException $e) {
            Log::error('Pixelforge API request', [
                ...$logContext,
                'duration_ms' => $this->millisecondsSince($startedAt),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $logContext += [
            'status' => $response->status(),
            'duration_ms' => $this->millisecondsSince($startedAt),
        ];

        if ($response->failed()) {
            $exception = PixelforgeException::fromResponse($response);

            Log::warning('Pixelforge API request', [
                ...$logContext,
                'error_code' => $exception->errorCode,
                'retry_after' => $exception->retryAfterSeconds,
            ]);

            throw $exception;
        }

        Log::info('Pixelforge API request', $logContext);

        return (array) $response->json();
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->apiKey)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(15);
    }

    /** Elapsed time for the log line (a duration, not money, so a float is fine here). */
    private function millisecondsSince(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
