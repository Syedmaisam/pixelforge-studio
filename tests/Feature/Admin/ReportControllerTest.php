<?php

namespace Tests\Feature\Admin;

use App\Models\Generation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The admin profit report and its check against Pixelforge's GET /v1/usage.
 * "Today" is frozen at 2026-11-17 12:00 UTC.
 */
class ReportControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-17 12:00:00', 'UTC'));
        config(['services.pixelforge.base_url' => 'https://pixelforge.test/v1']);
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get(route('admin.report'));

        $response->assertRedirect(route('login'));
    }

    public function test_a_user_who_is_not_an_admin_gets_403(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('admin.report'));

        $response->assertForbidden();
    }

    public function test_a_day_shows_charged_cost_and_profit_and_matches_pixelforge_exactly(): void
    {
        $this->fakeUsage(['2026-11-17' => '0.336315000']);
        $customer = User::factory()->create(['name' => 'Ada', 'email' => 'ada@example.test']);
        Generation::factory()->for($customer)
            ->succeeded(providerCostNanos: 336_315_000, chargedNanos: 420_393_750, completedAt: CarbonImmutable::parse('2026-11-17 10:00:00', 'UTC'))
            ->create();

        $response = $this->actingAs(User::factory()->admin()->create())->get(route('admin.report', ['days' => 7]));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('admin/Report')
            ->where('pixelforge_available', true)
            ->has('days', 7)
            ->where('days.0', [
                'date' => '2026-11-17',
                'generations' => 1,
                'charged' => '$0.420393750',
                'provider_cost' => '$0.336315000',
                'profit' => '$0.084078750',
                'pixelforge_cost' => '$0.336315000',
                'matches_pixelforge' => true,
            ])
            ->where('days.1.date', '2026-11-16')
            ->where('days.1.matches_pixelforge', true)
            ->where('by_user', [[
                'date' => '2026-11-17',
                'user' => 'Ada (ada@example.test)',
                'generations' => 1,
                'charged' => '$0.420393750',
                'provider_cost' => '$0.336315000',
                'profit' => '$0.084078750',
            ]])
            ->where('range', ['from' => '2026-11-11', 'to' => '2026-11-17', 'days' => 7]));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://pixelforge.test/v1/usage?start_date=2026-11-11&end_date=2026-11-17');
    }

    public function test_a_one_nanodollar_difference_from_pixelforge_is_a_mismatch(): void
    {
        $this->fakeUsage(['2026-11-17' => '0.336315001']);
        Generation::factory()
            ->succeeded(providerCostNanos: 336_315_000, chargedNanos: 420_393_750, completedAt: CarbonImmutable::parse('2026-11-17 10:00:00', 'UTC'))
            ->create();

        $response = $this->actingAs(User::factory()->admin()->create())->get(route('admin.report', ['days' => 7]));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('days.0.pixelforge_cost', '$0.336315001')
            ->where('days.0.matches_pixelforge', false));
    }

    public function test_a_generation_counts_on_the_utc_day_it_completed_not_the_day_it_was_created(): void
    {
        $this->fakeUsage(['2026-11-17' => '0.336315000']);
        Generation::factory()
            ->succeeded(providerCostNanos: 336_315_000, chargedNanos: 420_393_750, completedAt: CarbonImmutable::parse('2026-11-17 00:00:05', 'UTC'))
            ->create(['created_at' => CarbonImmutable::parse('2026-11-16 23:59:50', 'UTC')]);

        $response = $this->actingAs(User::factory()->admin()->create())->get(route('admin.report', ['days' => 7]));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('days.0.date', '2026-11-17')
            ->where('days.0.generations', 1)
            ->where('days.0.matches_pixelforge', true)
            ->where('days.1.date', '2026-11-16')
            ->where('days.1.generations', 0));
    }

    public function test_the_report_still_shows_our_totals_when_pixelforge_usage_is_unreachable(): void
    {
        Http::preventStrayRequests();
        Http::fake(['pixelforge.test/v1/usage*' => Http::response(['error' => ['code' => 'overloaded', 'message' => 'Overloaded']], 503)]);
        Generation::factory()
            ->succeeded(providerCostNanos: 336_315_000, chargedNanos: 420_393_750, completedAt: CarbonImmutable::parse('2026-11-17 10:00:00', 'UTC'))
            ->create();

        $response = $this->actingAs(User::factory()->admin()->create())->get(route('admin.report', ['days' => 7]));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('pixelforge_available', false)
            ->where('days.0.provider_cost', '$0.336315000')
            ->where('days.0.pixelforge_cost', null)
            ->where('days.0.matches_pixelforge', null));
    }

    public function test_the_range_is_capped_at_the_92_days_pixelforge_allows(): void
    {
        $this->fakeUsage([]);

        $response = $this->actingAs(User::factory()->admin()->create())->get(route('admin.report', ['days' => 500]));

        $response->assertInertia(fn (Assert $page) => $page
            ->has('days', 92)
            ->where('range.days', 92)
            ->where('range.from', '2026-08-18'));
    }

    /**
     * GET /v1/usage answers with the given cost per day; any other request fails.
     *
     * @param  array<string, string>  $costByDay  e.g. ['2026-11-17' => '0.336315000']
     */
    private function fakeUsage(array $costByDay): void
    {
        $data = [];

        foreach ($costByDay as $date => $cost) {
            $data[] = ['date' => $date, 'generations' => 1, 'cost_usd' => $cost];
        }

        Http::preventStrayRequests();
        Http::fake(['pixelforge.test/v1/usage*' => Http::response(['object' => 'usage', 'data' => $data])]);
    }
}
