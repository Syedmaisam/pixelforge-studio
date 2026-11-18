<?php

namespace App\Services;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pricing\CostCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY code that changes a generation's status or money.
 *
 * Webhooks, the submit job and the polling command all hand their Pixelforge
 * generation object to apply(). Because there is one path, every source gets
 * the same guarantees:
 *
 *   - Status only moves forward, so duplicate or out-of-order events are harmless.
 *   - Money is recorded exactly once, when the status first becomes final.
 *   - Failed generations are free.
 */
class GenerationStateUpdater
{
    public function __construct(
        private readonly CostCalculator $calculator,
        private readonly CreditLedger $ledger,
    ) {}

    /**
     * @param  array<string, mixed>  $remote  A Pixelforge generation object.
     */
    public function apply(Generation $generation, array $remote): Generation
    {
        return DB::transaction(function () use ($generation, $remote) {
            // Lock the row: two different events for the same generation
            // arriving at the same moment are handled one after the other.
            $generation = Generation::query()->whereKey($generation->id)->lockForUpdate()->firstOrFail();
            $generation->pixelforge_id ??= $remote['id'];

            $next = GenerationStatus::from($remote['status']);

            if ($generation->status->canMoveTo($next)) {
                $generation->status = $next;
                $generation->started_at = $this->utc($remote['started_at'] ?? null) ?? $generation->started_at;

                if ($next === GenerationStatus::Succeeded) {
                    $this->recordSuccess($generation, $remote, chargeUser: true);
                }

                if ($next === GenerationStatus::Failed) {
                    $this->recordFailure(
                        $generation,
                        $remote['error']['code'] ?? 'unknown_error',
                        $remote['error']['message'] ?? 'The generation failed.',
                        $this->utc($remote['completed_at'] ?? null),
                    );
                }
            } elseif ($this->isLateSuccessAfterWeGaveUp($generation, $next)) {
                // Rare: our submit job gave up after network errors, but Pixelforge
                // had in fact accepted and finished it. Pixelforge billed us, so we
                // record the provider cost (the admin report must match /v1/usage).
                // The user was told it failed, so they are not charged.
                $this->recordSuccess($generation, $remote, chargeUser: false);
            }

            $generation->save();

            return $generation;
        });
    }

    /**
     * Fail a generation that Pixelforge never accepted
     * (rejected prompt, daily limit, or we gave up retrying).
     */
    public function failBeforeSubmission(Generation $generation, string $errorCode, string $message): void
    {
        DB::transaction(function () use ($generation, $errorCode, $message) {
            $generation = Generation::query()->whereKey($generation->id)->lockForUpdate()->firstOrFail();

            if ($generation->status !== GenerationStatus::Pending) {
                return; // Pixelforge already accepted it; its webhooks will tell us the outcome
            }

            $generation->status = GenerationStatus::Failed;
            $this->recordFailure($generation, $errorCode, $message, CarbonImmutable::now('UTC'));
            $generation->save();
        });
    }

    /**
     * @param  array<string, mixed>  $remote
     */
    private function recordSuccess(Generation $generation, array $remote, bool $chargeUser): void
    {
        $generation->usage = $remote['usage'];
        $generation->rates = $remote['rates'];
        $generation->provider_cost_nanos = $this->calculator->providerCost($remote['usage'], $remote['rates']);
        $generation->image_url = $remote['image_url'];
        $generation->image_url_expires_at = $this->utc($remote['image_url_expires_at'] ?? null);
        $generation->completed_at = $this->utc($remote['completed_at']); // decides the billing day

        if (! $chargeUser) {
            return;
        }

        $generation->charged_nanos = $this->calculator->userPrice($generation->provider_cost_nanos);

        $this->ledger->release($generation);
        $this->ledger->charge($generation, $generation->charged_nanos);
    }

    private function recordFailure(Generation $generation, string $code, string $message, ?CarbonImmutable $completedAt): void
    {
        $generation->error_code = $code;
        $generation->error_message = $message;
        $generation->charged_nanos = 0; // failed generations are free
        $generation->completed_at = $completedAt ?? CarbonImmutable::now('UTC');

        $this->ledger->release($generation);
    }

    private function isLateSuccessAfterWeGaveUp(Generation $generation, GenerationStatus $next): bool
    {
        return $generation->status === GenerationStatus::Failed
            && $next === GenerationStatus::Succeeded
            && $generation->provider_cost_nanos === null;
    }

    private function utc(?string $timestamp): ?CarbonImmutable
    {
        return $timestamp === null ? null : CarbonImmutable::parse($timestamp)->utc();
    }
}
