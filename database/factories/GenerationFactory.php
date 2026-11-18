<?php

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Generations in a given state, written straight to the table.
 *
 * This skips the credit ledger, so use it for reports and polling tests.
 * Tests about holds and charges go through StartGeneration instead.
 *
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'status' => GenerationStatus::Pending,
            'prompt' => fake()->sentence(),
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'estimated_min_nanos' => 58_125_000,
            'estimated_max_nanos' => 86_250_000,
        ];
    }

    /**
     * Accepted by Pixelforge and still running.
     */
    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => GenerationStatus::Processing,
            'pixelforge_id' => 'gen_'.Str::random(12),
        ]);
    }

    /**
     * Finished and billed: Pixelforge charged us $providerCostNanos and we charged the user $chargedNanos.
     */
    public function succeeded(int $providerCostNanos, int $chargedNanos, CarbonImmutable $completedAt): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => GenerationStatus::Succeeded,
            'pixelforge_id' => 'gen_'.Str::random(12),
            'provider_cost_nanos' => $providerCostNanos,
            'charged_nanos' => $chargedNanos,
            'completed_at' => $completedAt,
        ]);
    }
}
