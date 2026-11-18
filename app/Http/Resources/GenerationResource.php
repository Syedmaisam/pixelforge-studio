<?php

namespace App\Http\Resources;

use App\Models\Generation;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What the Vue history list receives for one generation.
 *
 * Money is formatted here, on the server, so the browser never does money math.
 *
 * @mixin Generation
 */
class GenerationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $hasExpired = $this->imageHasExpired();

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'reference_images' => $this->reference_images,

            'estimate' => Money::format($this->estimated_min_nanos).' to '.Money::format($this->estimated_max_nanos),
            'charged' => $this->charged_nanos === null ? null : Money::format($this->charged_nanos),
            'charged_exact' => $this->charged_nanos === null ? null : '$'.Money::toDecimalString($this->charged_nanos),

            'image_url' => $hasExpired ? null : $this->image_url,
            'image_expired' => $hasExpired,

            'error_message' => $this->error_message,
            'created_at' => $this->created_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
