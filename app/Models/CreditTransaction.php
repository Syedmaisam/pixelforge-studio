<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line in a user's append-only credit ledger.
 *
 * A user's balance is the sum of their rows (cached on users.balance_nanos).
 * Only App\Services\CreditLedger writes these rows.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $generation_id
 * @property string $type
 * @property int $amount_nanos
 * @property string|null $description
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Guarded(['id'])]
class CreditTransaction extends Model
{
    public const WELCOME_BONUS = 'welcome_bonus';

    public const PURCHASE = 'purchase';

    /** Reserve the max estimate when a generation starts. */
    public const HOLD = 'hold';

    /** Give the hold back when the generation finishes. */
    public const RELEASE = 'release';

    /** Take the actual price when the generation succeeds. */
    public const CHARGE = 'charge';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount_nanos' => 'integer'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Generation, $this>
     */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }
}
