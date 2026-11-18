<?php

namespace App\Enums;

/**
 * pending    = saved by us, not yet accepted by Pixelforge
 * queued     = accepted by Pixelforge, waiting
 * processing = Pixelforge is generating
 * succeeded  = final, billed
 * failed     = final, free
 *
 * A generation only ever moves FORWARD. That single rule makes duplicate and
 * out-of-order webhooks harmless: a late "processing" after "succeeded" is ignored.
 */
enum GenerationStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function step(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::Queued => 1,
            self::Processing => 2,
            self::Succeeded, self::Failed => 3,
        };
    }

    public function isFinal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }

    public function canMoveTo(self $next): bool
    {
        return ! $this->isFinal() && $next->step() > $this->step();
    }
}
