<?php

namespace App\Jobs;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use App\Services\GenerationStateUpdater;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends a pending generation to Pixelforge, retrying politely.
 *
 *   429 rate / concurrency limit, 503 overloaded, other 5xx, network errors
 *       -> wait (at least Retry-After seconds) and try again, up to 6 attempts.
 *   400 blocked prompt, 422 invalid, 429 daily limit, 401 bad key, 409 key reused
 *       -> fail right away; retrying can't help.
 *
 * Every attempt sends the SAME Idempotency-Key (the generation's uuid),
 * so a retry can never create or bill a second generation.
 */
class SubmitGenerationToPixelforge implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    /** Seconds to wait before attempt 2, 3, 4, 5, 6. */
    private const BACKOFF = [2, 5, 10, 20, 30];

    public function __construct(public Generation $generation) {}

    public function handle(PixelforgeClient $pixelforge, GenerationStateUpdater $updater): void
    {
        $generation = $this->generation->fresh();

        // Already accepted (for example, a webhook arrived before an earlier
        // attempt returned) or already failed. Nothing left to do.
        if ($generation === null || $generation->status !== GenerationStatus::Pending) {
            return;
        }

        try {
            $remote = $pixelforge->createGeneration($generation->toPixelforgeRequest(), $generation->uuid);
        } catch (PixelforgeException $e) {
            if ($e->isRetryable()) {
                $this->release($this->secondsToWait($e->retryAfterSeconds));

                return;
            }

            if ($e->isOurFault()) {
                Log::error('Pixelforge refused a generation because of our setup', [
                    'generation' => $generation->uuid,
                    'code' => $e->errorCode,
                    'message' => $e->getMessage(),
                ]);
            }

            $updater->failBeforeSubmission($generation, $e->errorCode, $e->messageForUser());

            return;
        } catch (ConnectionException) {
            // Timeout or network error: we don't know if Pixelforge got it.
            // Retrying with the same Idempotency-Key is safe either way.
            $this->release($this->secondsToWait(null));

            return;
        }

        $updater->apply($generation, $remote);
    }

    /**
     * Delays Laravel uses if handle() throws something unexpected.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return self::BACKOFF;
    }

    /** Called by Laravel when all attempts are used up. */
    public function failed(?Throwable $exception): void
    {
        app(GenerationStateUpdater::class)->failBeforeSubmission(
            $this->generation,
            'submit_gave_up',
            'Pixelforge was too busy to accept this generation after several tries. You were not charged. Please try again.',
        );
    }

    private function secondsToWait(?int $retryAfter): int
    {
        $backoff = self::BACKOFF[$this->attempts() - 1] ?? 30;

        return max($retryAfter ?? 0, $backoff);
    }
}
