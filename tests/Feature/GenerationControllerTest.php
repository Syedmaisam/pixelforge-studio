<?php

namespace Tests\Feature;

use App\Actions\StartGeneration;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGenerationToPixelforge;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The studio page, the live estimate endpoint and starting a generation over HTTP.
 */
class GenerationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get(route('generations.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_the_studio_lists_only_the_signed_in_users_generations(): void
    {
        $this->fakePricing();
        $user = User::factory()->create();
        $mine = $this->startGeneration($user);
        $this->startGeneration(User::factory()->create());

        $response = $this->actingAs($user)->get(route('generations.index'));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('generations/Index')
            ->has('generations.data', 1)
            ->where('generations.data.0.id', $mine->id)
            ->where('sizes', ['1024x1024', '1536x1024', '1024x1536'])
            ->where('qualities', ['low', 'medium', 'high']));
    }

    public function test_the_studio_sends_money_already_formatted_for_a_finished_generation(): void
    {
        $this->fakePricing();
        $user = User::factory()->create();
        $generation = $this->startGeneration($user);
        $generation->forceFill([
            'status' => GenerationStatus::Succeeded,
            'charged_nanos' => 420_393_750,
            'image_url' => 'https://pixelforge.test/images/x.png',
            'image_url_expires_at' => now()->addDay(),
        ])->save();

        // fresh(): a real request loads the user from the database, after the hold.
        $response = $this->actingAs($user->fresh())->get(route('generations.index'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('generations.data.0.status', 'succeeded')
            ->where('generations.data.0.estimate', '$0.3578 to $0.4673')
            ->where('generations.data.0.charged', '$0.4204')
            ->where('generations.data.0.charged_exact', '$0.420393750')
            ->where('generations.data.0.image_url', 'https://pixelforge.test/images/x.png')
            ->where('generations.data.0.image_expired', false)
            ->where('credits', '$1.53'));
    }

    public function test_an_expired_image_link_is_hidden_and_flagged(): void
    {
        $this->fakePricing();
        $user = User::factory()->create();
        $generation = $this->startGeneration($user);
        $generation->forceFill([
            'status' => GenerationStatus::Succeeded,
            'image_url' => 'https://pixelforge.test/images/x.png',
            'image_url_expires_at' => now()->subMinute(),
        ])->save();

        $response = $this->actingAs($user)->get(route('generations.index'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('generations.data.0.image_url', null)
            ->where('generations.data.0.image_expired', true));
    }

    public function test_the_estimate_returns_the_price_range_and_that_the_user_can_afford_it(): void
    {
        $this->fakePricing();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson(route('generations.estimate', [
            'characters' => 400,
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => 2,
        ]));

        $response->assertOk()->assertExactJson([
            'min_nanos' => 357_781_250,
            'max_nanos' => 467_343_750,
            'min' => '$0.3578',
            'max' => '$0.4673',
            'can_afford' => true,
        ]);
    }

    public function test_the_estimate_says_the_user_cannot_afford_it_when_the_balance_is_below_the_worst_case(): void
    {
        $this->fakePricing();
        $user = User::factory()->create();
        $user->forceFill(['balance_nanos' => 467_343_749])->save(); // one nanodollar short

        $response = $this->actingAs($user)->getJson(route('generations.estimate', [
            'characters' => 400,
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => 2,
        ]));

        $response->assertOk()->assertJsonPath('can_afford', false);
    }

    public function test_the_estimate_rejects_a_size_pixelforge_does_not_offer_with_422(): void
    {
        $this->fakePricing();

        $response = $this->actingAs(User::factory()->create())->getJson(route('generations.estimate', [
            'characters' => 10,
            'size' => '800x600',
            'quality' => 'high',
            'reference_images' => 0,
        ]));

        $response->assertUnprocessable()->assertJsonValidationErrors(['size']);
    }

    public function test_generating_trims_the_prompt_drops_empty_urls_and_queues_the_submit_job(): void
    {
        $this->fakePricing();
        Queue::fake([SubmitGenerationToPixelforge::class]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('generations.store'), [
            'prompt' => '  A cat in a hat  ',
            'size' => '1024x1024',
            'quality' => 'low',
            'reference_images' => ['https://example.com/a.png', '', '   '],
        ]);

        $response->assertRedirect(route('generations.index'));
        $generation = Generation::query()->sole();
        $this->assertSame('A cat in a hat', $generation->prompt);
        $this->assertSame(['https://example.com/a.png'], $generation->reference_images);
        $this->assertSame(GenerationStatus::Pending, $generation->status);
        Queue::assertPushed(SubmitGenerationToPixelforge::class, 1);
    }

    public function test_generating_rejects_a_reference_image_that_is_not_an_http_url(): void
    {
        $this->fakePricing();
        Queue::fake([SubmitGenerationToPixelforge::class]);

        $response = $this->actingAs(User::factory()->create())->post(route('generations.store'), [
            'prompt' => 'A cat',
            'size' => '1024x1024',
            'quality' => 'low',
            'reference_images' => ['ftp://example.com/a.png'],
        ]);

        $response->assertSessionHasErrors(['reference_images.0' => 'Each reference image must be an http(s) URL.']);
        $this->assertDatabaseCount('generations', 0);
        Queue::assertNotPushed(SubmitGenerationToPixelforge::class);
    }

    public function test_generating_rejects_more_than_four_reference_images(): void
    {
        $this->fakePricing();
        Queue::fake([SubmitGenerationToPixelforge::class]);

        $response = $this->actingAs(User::factory()->create())->post(route('generations.store'), [
            'prompt' => 'A cat',
            'size' => '1024x1024',
            'quality' => 'low',
            'reference_images' => array_fill(0, 5, 'https://example.com/a.png'),
        ]);

        $response->assertSessionHasErrors(['reference_images' => 'You can add at most 4 reference images.']);
        $this->assertDatabaseCount('generations', 0);
        Queue::assertNotPushed(SubmitGenerationToPixelforge::class);
    }

    public function test_the_studio_still_renders_with_a_notice_when_pricing_is_unavailable(): void
    {
        $this->fakePricingDown();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('generations.index'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('generations/Index')
            ->where('pricingUnavailable', 'Pixelforge pricing is temporarily unavailable. Please try again in a minute.')
            ->where('sizes', []));
    }

    public function test_the_estimate_returns_503_when_pricing_is_unavailable(): void
    {
        $this->fakePricingDown();

        $response = $this->actingAs(User::factory()->create())->getJson(route('generations.estimate', [
            'characters' => 10,
            'size' => '1024x1024',
            'quality' => 'low',
            'reference_images' => 0,
        ]));

        $response->assertServiceUnavailable()
            ->assertExactJson(['message' => 'Pixelforge pricing is temporarily unavailable. Please try again in a minute.']);
    }

    public function test_generating_is_refused_without_a_charge_when_pricing_is_unavailable(): void
    {
        $this->fakePricingDown();
        Queue::fake([SubmitGenerationToPixelforge::class]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->from(route('generations.index'))->post(route('generations.store'), [
            'prompt' => 'A cat',
            'size' => '1024x1024',
            'quality' => 'low',
        ]);

        $response->assertRedirect(route('generations.index'));
        $this->assertDatabaseCount('generations', 0);
        $this->assertSame(2_000_000_000, $user->fresh()->balance_nanos);
        Queue::assertNotPushed(SubmitGenerationToPixelforge::class);
    }

    public function test_the_last_good_price_list_is_used_when_pixelforge_goes_down_after_the_cache_expires(): void
    {
        config(['services.pixelforge.base_url' => 'https://pixelforge.test/v1']);
        Http::preventStrayRequests();
        Http::fake([
            'pixelforge.test/v1/pricing' => Http::sequence()
                ->push($this->pricingFixture())
                ->push(['error' => ['code' => 'overloaded', 'message' => 'Overloaded']], 503),
        ]);
        $user = User::factory()->create();
        $query = ['characters' => 400, 'size' => '1536x1024', 'quality' => 'high', 'reference_images' => 2];
        $this->actingAs($user)->getJson(route('generations.estimate', $query))->assertOk();
        $this->travel(11)->minutes(); // past the 10-minute cache

        $response = $this->actingAs($user)->getJson(route('generations.estimate', $query));

        $response->assertOk()->assertJsonPath('max_nanos', 467_343_750);
        Http::assertSentCount(2);
    }

    /**
     * GET /pricing returns the saved fixture; any other outbound request fails.
     */
    private function fakePricing(): void
    {
        config(['services.pixelforge.base_url' => 'https://pixelforge.test/v1']);

        Http::preventStrayRequests();
        Http::fake([
            'pixelforge.test/v1/pricing' => Http::response($this->pricingFixture()),
        ]);
    }

    /**
     * GET /pricing fails, and nothing has been cached yet.
     */
    private function fakePricingDown(): void
    {
        config(['services.pixelforge.base_url' => 'https://pixelforge.test/v1']);

        Http::preventStrayRequests();
        Http::fake([
            'pixelforge.test/v1/pricing' => Http::response(['error' => ['code' => 'overloaded', 'message' => 'Overloaded']], 503),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function pricingFixture(): array
    {
        return json_decode((string) file_get_contents(base_path('tests/Fixtures/pricing.json')), true);
    }

    /**
     * The build spec's worked example: high, 1536x1024, 400 characters, 2 reference images.
     */
    private function startGeneration(User $user): Generation
    {
        Queue::fake([SubmitGenerationToPixelforge::class]);

        return app(StartGeneration::class)->handle($user, [
            'prompt' => str_repeat('a', 400),
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png'],
        ]);
    }
}
