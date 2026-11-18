<?php

namespace App\Actions;

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGenerationToPixelforge;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\PricingCatalog;
use App\Pricing\CostCalculator;
use App\Services\CreditLedger;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * What happens when a user presses "Generate":
 *
 *   1. Estimate the worst-case price.
 *   2. Lock the user's row, check they can afford the worst case.
 *   3. Save the generation locally (status "pending") and hold the credits.
 *   4. Queue a job that sends it to Pixelforge.
 *
 * Saving BEFORE calling Pixelforge is deliberate: the row's uuid is our
 * Idempotency-Key, and early webhooks find the row through metadata.local_id.
 */
class StartGeneration
{
    public function __construct(
        private readonly CostCalculator $calculator,
        private readonly PricingCatalog $pricing,
        private readonly CreditLedger $ledger,
    ) {}

    /**
     * @param  array{prompt: string, size: string, quality: string, reference_images: list<string>}  $input
     *
     * @throws ValidationException when the user can't afford the worst case
     */
    public function handle(User $user, array $input): Generation
    {
        $estimate = $this->calculator->estimate(
            $this->pricing->current(),
            mb_strlen($input['prompt']), // Unicode characters, not bytes
            $input['size'],
            $input['quality'],
            count($input['reference_images']),
        );

        $generation = DB::transaction(function () use ($user, $input, $estimate) {
            // Lock the user so two quick clicks can't both spend the same credit.
            $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($user->balance_nanos < $estimate->maxNanos) {
                throw ValidationException::withMessages([
                    'credits' => 'This generation can cost up to '.Money::format($estimate->maxNanos)
                        .', but your balance is '.Money::format($user->balance_nanos).'. Buy credits to continue.',
                ]);
            }

            $generation = $user->generations()->create([
                'uuid' => (string) Str::uuid(),
                'status' => GenerationStatus::Pending,
                'prompt' => $input['prompt'],
                'size' => $input['size'],
                'quality' => $input['quality'],
                'reference_images' => $input['reference_images'],
                'estimated_min_nanos' => $estimate->minNanos,
                'estimated_max_nanos' => $estimate->maxNanos,
            ]);

            $this->ledger->hold($generation);

            return $generation;
        });

        // Dispatched after the transaction has committed, so the job always finds the row.
        SubmitGenerationToPixelforge::dispatch($generation);

        return $generation;
    }
}
