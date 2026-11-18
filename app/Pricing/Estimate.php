<?php

namespace App\Pricing;

use App\Support\Money;

/**
 * What a generation will cost the USER (markup included), before it runs.
 *
 * Some token counts are only known after completion, so it is a range.
 * The real charge is always between $minNanos and $maxNanos, and
 * $maxNanos is what we hold from the user's balance.
 */
final readonly class Estimate
{
    public function __construct(
        public int $minNanos,
        public int $maxNanos,
    ) {}

    /**
     * @return array{min_nanos: int, max_nanos: int, min: string, max: string}
     */
    public function toArray(): array
    {
        return [
            'min_nanos' => $this->minNanos,
            'max_nanos' => $this->maxNanos,
            'min' => Money::format($this->minNanos),
            'max' => Money::format($this->maxNanos),
        ];
    }
}
