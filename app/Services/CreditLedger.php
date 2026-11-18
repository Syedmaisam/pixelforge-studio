<?php

namespace App\Services;

use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The only place that changes a user's credit balance.
 *
 * Every change is one row in credit_transactions (an append-only ledger)
 * plus the same amount added to users.balance_nanos, in one transaction.
 * So the balance can always be explained line by line.
 *
 * Lifecycle of a generation's money:
 *   start     -> hold    -max estimate   (reserve the worst case)
 *   succeeded -> release +max estimate   (give the reservation back)
 *                charge  -actual price   (take what it really cost)
 *   failed    -> release +max estimate   (free)
 */
class CreditLedger
{
    public function addCredits(User $user, int $nanos, string $type, ?string $description = null): void
    {
        $this->record($user->id, $type, $nanos, null, $description);

        // Keep the model we were given in step with the database row.
        $user->balance_nanos += $nanos;
        $user->syncOriginalAttribute('balance_nanos');
    }

    public function hold(Generation $generation): void
    {
        $this->record($generation->user_id, CreditTransaction::HOLD, -$generation->estimated_max_nanos, $generation->id, 'Reserved while generating');
    }

    public function release(Generation $generation): void
    {
        $this->record($generation->user_id, CreditTransaction::RELEASE, $generation->estimated_max_nanos, $generation->id, 'Reservation returned');
    }

    public function charge(Generation $generation, int $nanos): void
    {
        $this->record($generation->user_id, CreditTransaction::CHARGE, -$nanos, $generation->id, 'Generation cost');
    }

    private function record(int $userId, string $type, int $amountNanos, ?int $generationId, ?string $description): void
    {
        DB::transaction(function () use ($userId, $type, $amountNanos, $generationId, $description) {
            // The unique (generation_id, type) index makes a second hold,
            // release or charge for the same generation impossible.
            CreditTransaction::query()->create([
                'user_id' => $userId,
                'generation_id' => $generationId,
                'type' => $type,
                'amount_nanos' => $amountNanos,
                'description' => $description,
            ]);

            User::query()->whereKey($userId)->increment('balance_nanos', $amountNanos);
        });
    }
}
