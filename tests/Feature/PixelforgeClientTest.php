<?php

namespace Tests\Feature;

use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * Every request to Pixelforge is written to the Laravel log, without secrets.
 */
class PixelforgeClientTest extends TestCase
{
    private const API_KEY = 'pf_secret_test_key';

    public function test_a_successful_request_is_logged_as_info_with_its_status_and_duration(): void
    {
        Log::spy();
        Http::preventStrayRequests();
        Http::fake(['pixelforge.test/v1/pricing' => Http::response(['object' => 'pricing'])]);

        $this->client()->pricing();

        Log::shouldHaveReceived('info')->once()->with('Pixelforge API request', Mockery::on(
            fn (array $context): bool => $context['method'] === 'GET'
                && $context['path'] === '/pricing'
                && $context['status'] === 200
                && is_int($context['duration_ms']),
        ));
    }

    public function test_an_error_response_is_logged_as_a_warning_with_the_pixelforge_error_code(): void
    {
        Log::spy();
        Http::preventStrayRequests();
        Http::fake(['pixelforge.test/v1/generations' => Http::response(
            ['error' => ['code' => 'overloaded', 'message' => 'Overloaded']],
            503,
            ['Retry-After' => '2'],
        )]);

        try {
            $this->client()->createGeneration(['prompt' => 'A secret prompt'], 'uuid-123');
            $this->fail('Expected a PixelforgeException.');
        } catch (PixelforgeException) {
            // expected
        }

        Log::shouldHaveReceived('warning')->once()->with('Pixelforge API request', Mockery::on(
            fn (array $context): bool => $context['method'] === 'POST'
                && $context['path'] === '/generations'
                && $context['status'] === 503
                && $context['error_code'] === 'overloaded'
                && $context['retry_after'] === 2
                && $context['idempotency_key'] === 'uuid-123',
        ));
    }

    public function test_a_request_that_cannot_reach_pixelforge_is_logged_as_an_error_and_rethrown(): void
    {
        Log::spy();
        Http::preventStrayRequests();
        Http::fake(['pixelforge.test/v1/generations/gen_1' => Http::failedConnection('Connection timed out')]);

        $this->expectException(ConnectionException::class);

        try {
            $this->client()->getGeneration('gen_1');
        } finally {
            Log::shouldHaveReceived('error')->once()->with('Pixelforge API request', Mockery::on(
                fn (array $context): bool => $context['path'] === '/generations/gen_1'
                    && str_contains($context['error'], 'Connection timed out'),
            ));
        }
    }

    public function test_the_api_key_and_the_prompt_never_appear_in_the_log(): void
    {
        $logged = [];
        Log::listen(function ($message) use (&$logged): void {
            $logged[] = json_encode([$message->message, $message->context]);
        });
        Http::preventStrayRequests();
        Http::fake(['pixelforge.test/v1/generations' => Http::response(['id' => 'gen_1', 'status' => 'queued'], 202)]);

        $this->client()->createGeneration(['prompt' => 'A secret prompt'], 'uuid-123');

        $this->assertCount(1, $logged);
        $this->assertStringNotContainsString(self::API_KEY, $logged[0]);
        $this->assertStringNotContainsString('A secret prompt', $logged[0]);
    }

    private function client(): PixelforgeClient
    {
        return new PixelforgeClient('https://pixelforge.test/v1', self::API_KEY);
    }
}
