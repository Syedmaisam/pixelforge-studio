<?php

namespace Tests\Feature;

use App\Actions\StartGeneration;
use App\Enums\GenerationStatus;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * GET /cron/run: scheduler + queue over HTTP, for hosting panels.
 *
 * schedule:run is always faked here: for real it starts separate PHP processes
 * that would use the local database and the real Pixelforge API.
 */
class CronControllerTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'cron-test-token';

    public function test_it_returns_404_when_no_cron_token_is_configured(): void
    {
        config(['services.cron.token' => null]);
        Artisan::shouldReceive('call')->never();

        $response = $this->get(route('cron.run', ['token' => '']));

        $response->assertNotFound();
    }

    public function test_it_returns_404_for_a_wrong_token_and_runs_nothing(): void
    {
        config(['services.cron.token' => self::TOKEN]);
        Artisan::shouldReceive('call')->never();

        $response = $this->get(route('cron.run', ['token' => 'guess']));

        $response->assertNotFound();
    }

    public function test_a_valid_header_token_runs_the_scheduler_then_the_queue(): void
    {
        config(['services.cron.token' => self::TOKEN]);
        Artisan::shouldReceive('call')->once()->ordered()->with('schedule:run')->andReturn(0);
        Artisan::shouldReceive('call')->once()->ordered()->with('queue:work', [
            '--stop-when-empty' => true,
            '--max-time' => 25,
            '--sleep' => 1,
        ])->andReturn(0);
        Artisan::shouldReceive('output')->twice()->andReturn('done');

        $response = $this->withHeader('X-Cron-Token', self::TOKEN)->get(route('cron.run'));

        $response->assertOk()->assertSeeText('schedule:run')->assertSeeText('queue:work');
    }

    public function test_a_valid_query_token_is_accepted(): void
    {
        config(['services.cron.token' => self::TOKEN]);
        Artisan::shouldReceive('call')->twice()->andReturn(0);
        Artisan::shouldReceive('output')->twice()->andReturn('done');

        $response = $this->get(route('cron.run', ['token' => self::TOKEN]));

        $response->assertOk();
    }

    public function test_a_queued_submit_job_is_actually_sent_to_pixelforge(): void
    {
        config([
            'services.cron.token' => self::TOKEN,
            'queue.default' => 'database',
            'services.pixelforge.base_url' => 'https://pixelforge.test/v1',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'pixelforge.test/v1/pricing' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/pricing.json')), true)),
            'pixelforge.test/v1/generations' => Http::response(['id' => 'gen_cron', 'status' => 'queued', 'metadata' => []], 202),
        ]);
        $generation = app(StartGeneration::class)->handle(User::factory()->create(), [
            'prompt' => 'A cat',
            'size' => '1024x1024',
            'quality' => 'low',
            'reference_images' => [],
        ]);
        $this->assertDatabaseCount('jobs', 1);
        $realArtisan = app(Kernel::class); // fake only schedule:run; run the real queue worker
        Artisan::shouldReceive('call')->once()->with('schedule:run')->andReturn(0);
        Artisan::shouldReceive('call')->once()->with('queue:work', Mockery::type('array'))
            ->andReturnUsing(fn (string $command, array $options): int => $realArtisan->call($command, $options));
        Artisan::shouldReceive('output')->andReturnUsing(fn (): string => $realArtisan->output());

        $response = $this->get(route('cron.run', ['token' => self::TOKEN]));

        $response->assertOk();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame(GenerationStatus::Queued, $generation->fresh()->status);
        $this->assertSame('gen_cron', $generation->fresh()->pixelforge_id);
    }
}
