<?php

namespace App\Pixelforge;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * A thin wrapper around the Pixelforge HTTP API.
 *
 * It only sends requests and turns error responses into PixelforgeException.
 * It makes no decisions about retries or money; the callers do that.
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
        $response = $this->http()
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('/generations', $params);

        return $this->parse($response);
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
        return $this->parse($this->http()->get("/generations/{$pixelforgeId}"));
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
        return $this->parse($this->http()->get('/pricing'));
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
        return $this->parse($this->http()->get('/usage', [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]));
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

    /**
     * @return array<string, mixed>
     */
    private function parse(Response $response): array
    {
        if ($response->failed()) {
            throw PixelforgeException::fromResponse($response);
        }

        return (array) $response->json();
    }
}
