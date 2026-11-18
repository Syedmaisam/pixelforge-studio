<?php

namespace App\Pixelforge;

use App\Pricing\CostCalculator;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The current Pixelforge price list (GET /v1/pricing), cached for 10 minutes.
 *
 * Used ONLY for estimates and for the allowed sizes/qualities. The actual cost
 * of a generation always uses the "rates" returned on that generation,
 * because rates are frozen at creation.
 *
 * @phpstan-import-type Pricing from CostCalculator
 */
class PricingCatalog
{
    private const CACHE_KEY = 'pixelforge.pricing';

    private const LAST_GOOD_KEY = 'pixelforge.pricing.last_good';

    public function __construct(private readonly PixelforgeClient $pixelforge) {}

    /**
     * @return Pricing
     *
     * @throws PricingUnavailableException when Pixelforge can't be reached and we have no earlier copy
     */
    public function current(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addMinutes(10), function () {
            try {
                $pricing = $this->pixelforge->pricing();
                Cache::forever(self::LAST_GOOD_KEY, $pricing);

                return $pricing;
            } catch (Throwable $e) {
                // An estimate from slightly old prices is better than no estimate;
                // the real bill uses the generation's own rates anyway.
                return Cache::get(self::LAST_GOOD_KEY) ?? throw PricingUnavailableException::because($e);
            }
        });
    }

    /**
     * @return list<string> e.g. ['1024x1024', '1536x1024', '1024x1536']
     */
    public function sizes(): array
    {
        return array_keys($this->current()['tokens']['output']['medium']);
    }

    /**
     * @return list<string> e.g. ['low', 'medium', 'high']
     */
    public function qualities(): array
    {
        return array_keys($this->current()['tokens']['output']);
    }
}
