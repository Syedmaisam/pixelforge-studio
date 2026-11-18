<?php

namespace Tests\Feature;

use App\Actions\StartGeneration;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGenerationToPixelforge;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\PixelforgeClient;
use App\Services\GenerationStateUpdater;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The money path end to end: hold, submit, webhooks, charge or release.
 *
 * Every generation here is the worked example from the build spec:
 * high quality, 1536x1024, a 400-character prompt and 2 reference images.
 *   worst case (held):  467,343,750 nanodollars
 *   actual provider:    336,315,000 nanodollars
 *   actual user price:  420,393,750 nanodollars
 * A new user starts with the $2.00 welcome credit (2,000,000,000 nanodollars).
 */
class GenerationBillingTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsec_test';

    public function test_starting_a_generation_holds_the_worst_case_price_and_queues_the_submit_job(): void
    {
        $this->fakePixelforge();
        $user = User::factory()->create();

        $generation = $this->startGeneration($user);

        $this->assertSame(GenerationStatus::Pending, $generation->status);
        $this->assertSame(467_343_750, $generation->estimated_max_nanos);
        $this->assertSame(1_532_656_250, $user->fresh()->balance_nanos);
        $this->assertDatabaseHas('credit_transactions', [
            'generation_id' => $generation->id,
            'type' => CreditTransaction::HOLD,
            'amount_nanos' => -467_343_750,
        ]);
        Queue::assertPushed(
            SubmitGenerationToPixelforge::class,
            fn (SubmitGenerationToPixelforge $job): bool => $job->generation->is($generation),
        );
    }

    public function test_a_success_webhook_delivered_twice_charges_the_exact_price_once(): void
    {
        $this->fakePixelforge();
        $user = User::factory()->create();
        $generation = $this->startGeneration($user);

        $this->sendWebhook('evt_1', 'generation.succeeded', $this->remoteSucceeded($generation))->assertNoContent();
        $this->sendWebhook('evt_1', 'generation.succeeded', $this->remoteSucceeded($generation))->assertNoContent();

        $generation->refresh();
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame(336_315_000, $generation->provider_cost_nanos);
        $this->assertSame(420_393_750, $generation->charged_nanos);
        $this->assertSame(1_579_606_250, $user->fresh()->balance_nanos);
        $this->assertSame(1, CreditTransaction::query()->where('type', CreditTransaction::CHARGE)->count());
        $this->assertDatabaseCount('webhook_events', 1);
    }

    public function test_two_different_success_events_still_charge_once(): void
    {
        $this->fakePixelforge();
        $user = User::factory()->create();
        $generation = $this->startGeneration($user);

        $this->sendWebhook('evt_1', 'generation.succeeded', $this->remoteSucceeded($generation));
        $this->sendWebhook('evt_2', 'generation.succeeded', $this->remoteSucceeded($generation));

        $this->assertSame(1, CreditTransaction::query()->where('type', CreditTransaction::CHARGE)->count());
        $this->assertSame(1_579_606_250, $user->fresh()->balance_nanos);
        $this->assertDatabaseCount('webhook_events', 2);
    }

    public function test_a_processing_event_after_succeeded_does_not_move_the_status_back(): void
    {
        $this->fakePixelforge();
        $generation = $this->startGeneration(User::factory()->create());

        $this->sendWebhook('evt_done', 'generation.succeeded', $this->remoteSucceeded($generation));
        $this->sendWebhook('evt_late', 'generation.processing', $this->remoteGeneration($generation, 'processing'))->assertNoContent();

        $generation->refresh();
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame(420_393_750, $generation->charged_nanos);
    }

    public function test_a_failed_generation_is_free_and_returns_the_whole_hold(): void
    {
        $this->fakePixelforge();
        $user = User::factory()->create();
        $generation = $this->startGeneration($user);

        $this->sendWebhook('evt_f', 'generation.failed', $this->remoteGeneration($generation, 'failed', [
            'error' => ['code' => 'generation_failed', 'message' => 'The model could not produce an image.'],
        ]))->assertNoContent();

        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('generation_failed', $generation->error_code);
        $this->assertSame(0, $generation->charged_nanos);
        $this->assertSame(2_000_000_000, $user->fresh()->balance_nanos);
        $this->assertDatabaseHas('credit_transactions', [
            'generation_id' => $generation->id,
            'type' => CreditTransaction::RELEASE,
            'amount_nanos' => 467_343_750,
        ]);
    }

    public function test_a_webhook_that_arrives_before_the_submit_returns_is_applied_and_not_undone(): void
    {
        $this->fakePixelforge();
        $generation = $this->startGeneration(User::factory()->create()); // pending, no pixelforge_id yet

        $this->sendWebhook('evt_early', 'generation.processing', $this->remoteGeneration($generation, 'processing'))->assertNoContent();
        // The POST response ("queued") lands after the webhook...
        app(GenerationStateUpdater::class)->apply($generation, $this->remoteGeneration($generation, 'queued'));
        // ...and a retried job run finds nothing left to submit.
        (new SubmitGenerationToPixelforge($generation))->handle(app(PixelforgeClient::class), app(GenerationStateUpdater::class));

        $generation->refresh();
        $this->assertSame(GenerationStatus::Processing, $generation->status);
        $this->assertSame('gen_test_'.$generation->id, $generation->pixelforge_id);
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/generations'));
    }

    public function test_a_webhook_with_a_bad_signature_returns_400_and_stores_nothing(): void
    {
        $this->fakePixelforge();
        $generation = $this->startGeneration(User::factory()->create());

        $response = $this->sendWebhook('evt_x', 'generation.succeeded', $this->remoteSucceeded($generation), secret: 'whsec_wrong');

        $response->assertBadRequest();
        $this->assertSame(GenerationStatus::Pending, $generation->fresh()->status);
        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_a_ping_event_returns_204_and_is_recorded(): void
    {
        $this->fakePixelforge();

        $response = $this->sendWebhook('evt_ping', 'ping', []);

        $response->assertNoContent();
        $this->assertDatabaseHas('webhook_events', ['event_id' => 'evt_ping', 'type' => 'ping']);
    }

    public function test_an_overloaded_pixelforge_releases_the_job_to_retry_after_the_retry_after_delay(): void
    {
        $this->fakePixelforge(createResponse: Http::response(
            ['error' => ['type' => 'api_error', 'code' => 'overloaded', 'message' => 'Overloaded']],
            503,
            ['Retry-After' => '2'],
        ));
        $user = User::factory()->create();
        $generation = $this->startGeneration($user);
        $job = (new SubmitGenerationToPixelforge($generation))->withFakeQueueInteractions();

        $job->handle(app(PixelforgeClient::class), app(GenerationStateUpdater::class));

        $job->assertReleased(delay: 2);
        $this->assertSame(GenerationStatus::Pending, $generation->fresh()->status);
        $this->assertSame(1_532_656_250, $user->fresh()->balance_nanos); // still held
    }

    public function test_a_blocked_prompt_fails_immediately_and_is_free(): void
    {
        $this->fakePixelforge(createResponse: Http::response(
            ['error' => ['type' => 'invalid_request_error', 'code' => 'content_policy_violation', 'message' => 'Rejected']],
            400,
        ));
        $user = User::factory()->create();
        $generation = $this->startGeneration($user);
        $job = (new SubmitGenerationToPixelforge($generation))->withFakeQueueInteractions();

        $job->handle(app(PixelforgeClient::class), app(GenerationStateUpdater::class));

        $job->assertNotReleased();
        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('content_policy_violation', $generation->error_code);
        $this->assertSame('Pixelforge rejected this prompt. Try rewording it.', $generation->error_message);
        $this->assertSame(0, $generation->charged_nanos);
        $this->assertSame(2_000_000_000, $user->fresh()->balance_nanos);
    }

    public function test_the_daily_limit_fails_immediately_without_a_retry(): void
    {
        $this->fakePixelforge(createResponse: Http::response(
            ['error' => ['type' => 'rate_limit_error', 'code' => 'daily_limit_exceeded', 'message' => 'Daily limit']],
            429,
            ['Retry-After' => '3600'],
        ));
        $user = User::factory()->create();
        $generation = $this->startGeneration($user);
        $job = (new SubmitGenerationToPixelforge($generation))->withFakeQueueInteractions();

        $job->handle(app(PixelforgeClient::class), app(GenerationStateUpdater::class));

        $job->assertNotReleased();
        $this->assertSame('daily_limit_exceeded', $generation->fresh()->error_code);
        $this->assertSame(2_000_000_000, $user->fresh()->balance_nanos);
    }

    public function test_a_late_success_after_the_job_gave_up_records_the_provider_cost_but_charges_the_user_nothing(): void
    {
        $this->fakePixelforge();
        $user = User::factory()->create();
        $generation = $this->startGeneration($user);
        (new SubmitGenerationToPixelforge($generation))->failed(null); // all retries used up

        $this->sendWebhook('evt_late_ok', 'generation.succeeded', $this->remoteSucceeded($generation))->assertNoContent();

        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('submit_gave_up', $generation->error_code);
        $this->assertSame(336_315_000, $generation->provider_cost_nanos); // Pixelforge billed us
        $this->assertSame(0, $generation->charged_nanos);
        $this->assertSame(2_000_000_000, $user->fresh()->balance_nanos);
        $this->assertSame(0, CreditTransaction::query()->where('type', CreditTransaction::CHARGE)->count());
    }

    public function test_a_successful_submit_sends_the_uuid_as_idempotency_key_and_stores_the_pixelforge_id(): void
    {
        $this->fakePixelforge(createResponse: Http::response(['id' => 'gen_abc', 'status' => 'queued', 'metadata' => []], 202));
        $generation = $this->startGeneration(User::factory()->create());

        (new SubmitGenerationToPixelforge($generation))->handle(app(PixelforgeClient::class), app(GenerationStateUpdater::class));

        $generation->refresh();
        $this->assertSame(GenerationStatus::Queued, $generation->status);
        $this->assertSame('gen_abc', $generation->pixelforge_id);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://pixelforge.test/v1/generations'
            && $request->hasHeader('Idempotency-Key', $generation->uuid)
            && $request->data() === [
                'prompt' => str_repeat('a', 400),
                'size' => '1536x1024',
                'quality' => 'high',
                'metadata' => ['local_id' => $generation->uuid, 'user_id' => (string) $generation->user_id],
                'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png'],
            ]);
    }

    public function test_a_submit_without_reference_images_leaves_the_key_out_of_the_request(): void
    {
        $this->fakePixelforge(createResponse: Http::response(['id' => 'gen_abc', 'status' => 'queued', 'metadata' => []], 202));
        $generation = $this->startGeneration(User::factory()->create(), referenceImages: []);

        (new SubmitGenerationToPixelforge($generation))->handle(app(PixelforgeClient::class), app(GenerationStateUpdater::class));

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/generations')
            && ! array_key_exists('reference_images', $request->data()));
    }

    public function test_a_user_who_cannot_afford_the_worst_case_gets_a_credits_error_and_no_generation(): void
    {
        $this->fakePixelforge();
        $user = User::factory()->create();
        $user->forceFill(['balance_nanos' => 100])->save();

        $response = $this->actingAs($user)->post(route('generations.store'), [
            'prompt' => 'A cat',
            'size' => '1024x1024',
            'quality' => 'high',
        ]);

        $response->assertSessionHasErrors([
            // "A cat", high, 1024x1024: (3 x 5,000 + 4,300 x 40,000 + 8,000 x 12,500) x 1.25 = 340,018,750
            'credits' => 'This generation can cost up to $0.3400, but your balance is $0.0000. Buy credits to continue.',
        ]);
        $this->assertDatabaseCount('generations', 0);
        $this->assertSame(100, $user->fresh()->balance_nanos);
        Queue::assertNotPushed(SubmitGenerationToPixelforge::class);
    }

    /**
     * Point the app at a fake Pixelforge: GET /pricing returns the saved fixture,
     * POST /generations returns $createResponse, and any other request fails.
     */
    private function fakePixelforge(?PromiseInterface $createResponse = null): void
    {
        config([
            'services.pixelforge.base_url' => 'https://pixelforge.test/v1',
            'services.pixelforge.api_key' => 'pf_test_key',
            'services.pixelforge.webhook_secret' => self::WEBHOOK_SECRET,
        ]);

        Queue::fake([SubmitGenerationToPixelforge::class]);
        Http::preventStrayRequests();
        Http::fake(array_filter([
            'pixelforge.test/v1/pricing' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/pricing.json')), true)),
            'pixelforge.test/v1/generations' => $createResponse,
        ]));
    }

    /**
     * Start the worked-example generation for $user.
     *
     * @param  list<string>  $referenceImages
     */
    private function startGeneration(User $user, array $referenceImages = ['https://example.com/a.png', 'https://example.com/b.png']): Generation
    {
        return app(StartGeneration::class)->handle($user, [
            'prompt' => str_repeat('a', 400),
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => $referenceImages,
        ]);
    }

    /**
     * A Pixelforge generation object for our local row.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function remoteGeneration(Generation $generation, string $status, array $extra = []): array
    {
        return array_merge([
            'id' => 'gen_test_'.$generation->id,
            'object' => 'generation',
            'status' => $status,
            'metadata' => ['local_id' => $generation->uuid, 'user_id' => (string) $generation->user_id],
            'image_url' => null,
            'image_url_expires_at' => null,
            'usage' => null,
            'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
            'error' => null,
            'created_at' => '2026-11-17T10:00:00Z',
            'started_at' => $status === 'queued' ? null : '2026-11-17T10:00:02Z',
            'completed_at' => in_array($status, ['succeeded', 'failed'], true) ? '2026-11-17T10:00:20Z' : null,
        ], $extra);
    }

    /**
     * A succeeded generation whose usage costs exactly 336,315,000 nanodollars:
     * 103 x 5,000 + 1,530 x 10,000 + 6,450 x 40,000 + 5,000 x 12,500.
     *
     * @return array<string, mixed>
     */
    private function remoteSucceeded(Generation $generation): array
    {
        return $this->remoteGeneration($generation, 'succeeded', [
            'image_url' => 'https://pixelforge.test/images/x.png',
            'image_url_expires_at' => '2099-01-01T00:00:00Z',
            'usage' => ['input_text_tokens' => 103, 'input_image_tokens' => 1530, 'output_tokens' => 6450, 'reasoning_tokens' => 5000, 'total_tokens' => 13083],
        ]);
    }

    /**
     * Send a webhook signed the way Pixelforge signs it.
     *
     * @param  array<string, mixed>  $generationObject
     */
    private function sendWebhook(string $eventId, string $type, array $generationObject, string $secret = self::WEBHOOK_SECRET): TestResponse
    {
        $body = (string) json_encode([
            'id' => $eventId,
            'object' => 'event',
            'type' => $type,
            'created_at' => '2026-11-17T10:00:20Z',
            'data' => ['object' => $generationObject],
        ]);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

        return $this->call('POST', '/webhooks/pixelforge', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], $body);
    }
}
