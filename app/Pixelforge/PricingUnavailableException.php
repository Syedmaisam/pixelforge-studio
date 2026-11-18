<?php

namespace App\Pixelforge;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use RuntimeException;
use Throwable;

/**
 * Pixelforge's price list can't be loaded and we have no earlier copy to fall back on
 * (for example, Pixelforge is down on the very first request after a deploy).
 *
 * Without prices we can't show an estimate or check the balance, so we refuse to
 * start generations and tell the user to try again, instead of failing with a 500.
 */
class PricingUnavailableException extends RuntimeException
{
    public const MESSAGE = 'Pixelforge pricing is temporarily unavailable. Please try again in a minute.';

    public static function because(Throwable $previous): self
    {
        return new self(self::MESSAGE, 0, $previous);
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => self::MESSAGE], 503);
        }

        Inertia::flash('toast', ['type' => 'error', 'message' => self::MESSAGE]);

        return back();
    }
}
