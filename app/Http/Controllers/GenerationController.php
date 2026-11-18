<?php

namespace App\Http\Controllers;

use App\Actions\StartGeneration;
use App\Http\Requests\StoreGenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Pixelforge\PricingCatalog;
use App\Pixelforge\PricingUnavailableException;
use App\Pricing\CostCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The image studio: the page, the live price estimate, and starting a generation.
 */
class GenerationController extends Controller
{
    /**
     * The studio page: the form on top, the user's history below.
     */
    public function index(Request $request, PricingCatalog $pricing): Response
    {
        $generations = $request->user()
            ->generations()
            ->latest('id')
            ->paginate(12);

        // History stays visible even when Pixelforge's price list can't be loaded;
        // only the form is replaced by a notice.
        try {
            $sizes = $pricing->sizes();
            $qualities = $pricing->qualities();
        } catch (PricingUnavailableException) {
            $sizes = $qualities = [];
        }

        return Inertia::render('generations/Index', [
            'generations' => GenerationResource::collection($generations),
            'sizes' => $sizes,
            'qualities' => $qualities,
            'pricingUnavailable' => $sizes === [] ? PricingUnavailableException::MESSAGE : null,
        ]);
    }

    /**
     * Live price estimate while the user types (GET, so no CSRF token needed).
     * We only need the prompt LENGTH, not the prompt itself.
     */
    public function estimate(Request $request, PricingCatalog $pricing, CostCalculator $calculator): JsonResponse
    {
        $input = $request->validate([
            'characters' => ['required', 'integer', 'min:0', 'max:4000'],
            'size' => ['required', Rule::in($pricing->sizes())],
            'quality' => ['required', Rule::in($pricing->qualities())],
            'reference_images' => ['required', 'integer', 'min:0', 'max:4'],
        ]);

        $estimate = $calculator->estimate(
            $pricing->current(),
            (int) $input['characters'],
            $input['size'],
            $input['quality'],
            (int) $input['reference_images'],
        );

        return response()->json([
            ...$estimate->toArray(),
            'can_afford' => $request->user()->balance_nanos >= $estimate->maxNanos,
        ]);
    }

    public function store(StoreGenerationRequest $request, StartGeneration $startGeneration): RedirectResponse
    {
        $startGeneration->handle($request->user(), $request->generationInput());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Generation started. It will appear below.']);

        return to_route('generations.index');
    }
}
