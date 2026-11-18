<?php

namespace Tests\Feature;

use App\Actions\StartGeneration;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGenerationToPixelforge;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * pixelforge:sync, the polling safety net for webhooks that never arrive.
 */
class SyncActiveGenerationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.pixelforge.base_url' => 'https://pixelforge.test/v1']);
    }

    public function test_a_generation_quiet_for_over_two_minutes_is_fetched_and_billed_once(): void
    {
        Queue::fake([SubmitGenerationToPixelforge::class]);
        Http::preventStrayRequests();
        Http::fake([
            'pixelforge.test/v1/pricing' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/pricing.json')), true)),
            'pixelforge.test/v1/generations/gen_quiet' => fn () => Http::response($this->succeededRemote()),
        ]);
        $user = User::factory()->create();
        $generation = app(StartGeneration::class)->handle($user, [
            'prompt' => str_repeat('a', 400),
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png'],
        ]);
        $generation->forceFill(['status' => GenerationStatus::Processing, 'pixelforge_id' => 'gen_quiet'])->save();
        $this->travel(3)->minutes();

        $this->artisan('pixelforge:sync')->expectsOutput('Checked 1 generation(s).')->assertSuccessful();

        $generation->refresh();
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame(420_393_750, $generation->charged_nanos);
        $this->assertSame(1_579_606_250, $user->fresh()->balance_nanos);
    }

    public function test_a_generation_updated_in_the_last_two_minutes_is_left_alone(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        Generation::factory()->processing()->create();

        $this->artisan('pixelforge:sync')->expectsOutput('Checked 0 generation(s).')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_a_pixelforge_error_is_reported_and_the_generation_is_left_unchanged(): void
    {
        Http::preventStrayRequests();
        Http::fake(['pixelforge.test/v1/generations/*' => Http::response(['error' => ['code' => 'overloaded', 'message' => 'Overloaded']], 503)]);
        $generation = Generation::factory()->processing()->create(['updated_at' => now()->subMinutes(5)]);

        $this->artisan('pixelforge:sync')
            ->expectsOutput("Could not sync {$generation->pixelforge_id}: Overloaded")
            ->assertSuccessful();

        $this->assertSame(GenerationStatus::Processing, $generation->fresh()->status);
    }

    public function test_the_sync_is_scheduled_every_minute_without_overlapping(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains((string) $event->command, 'pixelforge:sync'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    /**
     * Pixelforge's answer for gen_quiet: succeeded, costing exactly 336,315,000 nanodollars.
     *
     * @return array<string, mixed>
     */
    private function succeededRemote(): array
    {
        $generation = Generation::query()->sole();

        return [
            'id' => 'gen_quiet',
            'status' => 'succeeded',
            'metadata' => ['local_id' => $generation->uuid],
            'image_url' => 'https://pixelforge.test/images/x.png',
            'image_url_expires_at' => '2099-01-01T00:00:00Z',
            'usage' => ['input_text_tokens' => 103, 'input_image_tokens' => 1530, 'output_tokens' => 6450, 'reasoning_tokens' => 5000, 'total_tokens' => 13083],
            'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
            'error' => null,
            'started_at' => '2026-11-17T10:00:02Z',
            'completed_at' => '2026-11-17T10:00:20Z',
        ];
    }
}
