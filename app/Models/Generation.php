<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use Carbon\CarbonImmutable;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One image generation requested by a user, mirrored from Pixelforge.
 *
 * The row is saved BEFORE we call Pixelforge: its uuid is the Idempotency-Key
 * and travels in metadata.local_id, so retries never duplicate and a webhook
 * that arrives before our POST returns can still find it.
 *
 * All money columns are integer nanodollars (1 USD = 1,000,000,000).
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property string|null $pixelforge_id
 * @property GenerationStatus $status
 * @property string $prompt
 * @property string $size
 * @property string $quality
 * @property list<string> $reference_images
 * @property int $estimated_min_nanos
 * @property int $estimated_max_nanos
 * @property int|null $provider_cost_nanos
 * @property int|null $charged_nanos
 * @property array<string, string>|null $rates
 * @property array<string, int>|null $usage
 * @property string|null $image_url
 * @property CarbonImmutable|null $image_url_expires_at
 * @property string|null $error_code
 * @property string|null $error_message
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Guarded(['id'])]
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'reference_images' => 'array',
            'rates' => 'array',
            'usage' => 'array',
            'estimated_min_nanos' => 'integer',
            'estimated_max_nanos' => 'integer',
            'provider_cost_nanos' => 'integer',
            'charged_nanos' => 'integer',
            'image_url_expires_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Find the local row for a Pixelforge generation object.
     *
     * We look at metadata.local_id first, because a webhook can arrive
     * BEFORE our POST returned and we stored the Pixelforge id.
     *
     * @param  array<string, mixed>  $remote  A Pixelforge generation object.
     */
    public static function findForPixelforge(array $remote): ?self
    {
        $localId = $remote['metadata']['local_id'] ?? null;

        if ($localId !== null) {
            return static::query()->where('uuid', $localId)->first();
        }

        return static::query()->where('pixelforge_id', $remote['id'] ?? null)->first();
    }

    /**
     * The exact JSON body we send to POST /v1/generations.
     *
     * @return array{prompt: string, size: string, quality: string, metadata: array<string, string>, reference_images?: list<string>}
     */
    public function toPixelforgeRequest(): array
    {
        $body = [
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'metadata' => [
                'local_id' => $this->uuid,
                'user_id' => (string) $this->user_id,
            ],
        ];

        // Pixelforge rejects unknown or malformed parameters instead of ignoring
        // them, so only send reference_images when there are some.
        if ($this->reference_images !== []) {
            $body['reference_images'] = $this->reference_images;
        }

        return $body;
    }

    /**
     * Pixelforge image links expire; we don't download images, we just say so.
     */
    public function imageHasExpired(): bool
    {
        return $this->image_url_expires_at !== null && $this->image_url_expires_at->isPast();
    }
}
