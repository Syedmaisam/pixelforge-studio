<?php

namespace App\Pricing;

use App\Support\Money;

/**
 * Every price calculation in the app lives here, so there is one place to check.
 *
 * Provider cost = what Pixelforge charges us.
 * User price    = provider cost + 25% markup, rounded UP to the next nanodollar.
 *
 * Pixelforge never tells us a price; we work it out from token counts and rates.
 *
 * @phpstan-type Rates array<string, string>
 * @phpstan-type Usage array<string, int>
 * @phpstan-type Pricing array{
 *     rates: Rates,
 *     tokens: array{
 *         characters_per_text_token: int,
 *         text_token_variance: float|int,
 *         per_reference_image: int,
 *         output: array<string, array<string, int>>,
 *         reasoning: array<string, array{min: int, max: int}>,
 *     },
 * }
 */
final class CostCalculator
{
    public const MARKUP_PERCENT = 25;

    private const TOKEN_TYPES = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    /**
     * The provider cost of a set of token counts, in nanodollars.
     *
     * For a finished generation, pass the generation's OWN "rates" object.
     * Rates are frozen when the generation is created, so never use today's pricing here.
     *
     * @param  Usage  $usage  Token counts, e.g. the generation's "usage" object.
     * @param  Rates  $rates  USD per 1M tokens, as decimal strings.
     */
    public function providerCost(array $usage, array $rates): int
    {
        $total = 0;

        foreach (self::TOKEN_TYPES as $tokenType) {
            $tokens = $usage[$tokenType] ?? 0;
            $total += $tokens * Money::ratePerTokenInNanos($rates[$tokenType]);
        }

        return $total;
    }

    /**
     * What we charge the user for a given provider cost.
     *
     * 25% of a 9-decimal amount can need 11 decimals, so we round UP to the
     * next whole nanodollar ($0.000000001). We never undercharge, and the
     * difference is far too small for anyone to see.
     */
    public function userPrice(int $providerCostNanos): int
    {
        $withMarkup = $providerCostNanos * (100 + self::MARKUP_PERCENT);

        return intdiv($withMarkup + 99, 100); // integer ceil(x / 100)
    }

    /**
     * Estimate the user price BEFORE submitting.
     *
     * Known exactly up front:  image tokens (765 each), output tokens (by quality and size).
     * Only known as a range:   text tokens (about chars/4, +/- 15%) and reasoning tokens (by quality).
     *
     * @param  Pricing  $pricing  The response of GET /v1/pricing.
     */
    public function estimate(array $pricing, int $promptCharacters, string $size, string $quality, int $referenceImages): Estimate
    {
        $tokens = $pricing['tokens'];

        // Text: ceil(characters / 4), then widen by the published variance.
        // The variance (0.15) is a percentage, not money: it becomes the whole number 15.
        $charactersPerToken = $tokens['characters_per_text_token'];
        $textGuess = intdiv($promptCharacters + $charactersPerToken - 1, $charactersPerToken);
        $variancePercent = (int) round($tokens['text_token_variance'] * 100);
        $textMin = intdiv($textGuess * (100 - $variancePercent), 100);      // round down
        $textMax = intdiv($textGuess * (100 + $variancePercent) + 99, 100); // round up

        $imageTokens = $referenceImages * $tokens['per_reference_image'];
        $outputTokens = $tokens['output'][$quality][$size];
        $reasoning = $tokens['reasoning'][$quality];

        $cheapest = $this->providerCost([
            'input_text_tokens' => $textMin,
            'input_image_tokens' => $imageTokens,
            'output_tokens' => $outputTokens,
            'reasoning_tokens' => $reasoning['min'],
        ], $pricing['rates']);

        $mostExpensive = $this->providerCost([
            'input_text_tokens' => $textMax,
            'input_image_tokens' => $imageTokens,
            'output_tokens' => $outputTokens,
            'reasoning_tokens' => $reasoning['max'],
        ], $pricing['rates']);

        return new Estimate(
            minNanos: $this->userPrice($cheapest),
            maxNanos: $this->userPrice($mostExpensive),
        );
    }
}
