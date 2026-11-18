<?php

namespace Tests\Unit;

use App\Pricing\CostCalculator;
use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The money rules, checked against the examples in the Pixelforge docs.
 * Uses a saved copy of GET /v1/pricing (tests/Fixtures/pricing.json).
 */
class CostCalculatorTest extends TestCase
{
    private const DOCS_RATES = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    public function test_rates_convert_to_nanodollars_per_token_without_floats(): void
    {
        $this->assertSame(12_500, Money::ratePerTokenInNanos('12.50'));
        $this->assertSame(5_000, Money::ratePerTokenInNanos('5.00'));
        $this->assertSame(46_125_000, Money::parseDollars('0.046125000'));
    }

    public function test_a_rate_that_would_lose_precision_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::ratePerTokenInNanos('1.2345');
    }

    public function test_it_matches_the_usage_example_from_the_pixelforge_docs(): void
    {
        $cost = (new CostCalculator)->providerCost(
            ['input_text_tokens' => 41, 'input_image_tokens' => 0, 'output_tokens' => 3300, 'reasoning_tokens' => 2950],
            $this->pricing()['rates'],
        );

        $this->assertSame('0.169080000', Money::toDecimalString($cost));
    }

    public function test_actual_cost_of_the_worked_example(): void
    {
        $cost = (new CostCalculator)->providerCost(
            ['input_text_tokens' => 103, 'input_image_tokens' => 1530, 'output_tokens' => 6450, 'reasoning_tokens' => 5000],
            self::DOCS_RATES,
        );

        $this->assertSame(336_315_000, $cost);
    }

    public function test_user_price_adds_25_percent_and_rounds_up_to_the_next_nanodollar(): void
    {
        $calculator = new CostCalculator;

        $this->assertSame(420_393_750, $calculator->userPrice(336_315_000));
        $this->assertSame(2, $calculator->userPrice(1)); // 1.25 -> 2, never undercharge
        $this->assertSame(0, $calculator->userPrice(0));
    }

    public function test_it_matches_the_worst_case_estimate_from_the_pixelforge_docs(): void
    {
        // high, 1536x1024, 400 characters, 2 reference images => provider max $0.373875
        $estimate = (new CostCalculator)->estimate($this->pricing(), 400, '1536x1024', 'high', 2);

        $this->assertSame(467_343_750, $estimate->maxNanos); // 373_875_000 * 1.25
        $this->assertSame(357_781_250, $estimate->minNanos); // 286_225_000 * 1.25
    }

    public function test_money_is_formatted_for_display_with_half_up_rounding(): void
    {
        $this->assertSame('$0.4204', Money::format(420_393_750));
        $this->assertSame('$2.00', Money::format(Money::dollars(2), 2));
        $this->assertSame('0.420393750', Money::toDecimalString(420_393_750));
        $this->assertSame('-0.000000001', Money::toDecimalString(-1));
    }

    /**
     * @return array{rates: array<string, string>, tokens: array{characters_per_text_token: int, text_token_variance: float, per_reference_image: int, output: array<string, array<string, int>>, reasoning: array<string, array{min: int, max: int}>}}
     */
    private function pricing(): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/../Fixtures/pricing.json'), true);
    }
}
