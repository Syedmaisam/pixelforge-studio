<?php

namespace App\Console\Commands;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use App\Services\GenerationStateUpdater;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;

/**
 * Safety net for missed webhooks (Pixelforge doesn't guarantee delivery).
 *
 * Runs every minute and asks Pixelforge about generations that have been
 * queued or processing for more than 2 minutes without news. The answer goes
 * through GenerationStateUpdater, exactly like a webhook would.
 */
class SyncActiveGenerations extends Command
{
    /** Stay well under Pixelforge's 60 requests per minute. */
    private const MAX_PER_RUN = 20;

    protected $signature = 'pixelforge:sync';

    protected $description = 'Poll Pixelforge for queued or processing generations that have gone quiet';

    public function handle(PixelforgeClient $pixelforge, GenerationStateUpdater $updater): int
    {
        $quiet = Generation::query()
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->whereNotNull('pixelforge_id')
            ->where('updated_at', '<', now()->subMinutes(2))
            ->oldest('updated_at')
            ->limit(self::MAX_PER_RUN)
            ->get();

        foreach ($quiet as $generation) {
            $pixelforgeId = $generation->pixelforge_id;

            if ($pixelforgeId === null) {
                continue;
            }

            try {
                $updater->apply($generation, $pixelforge->getGeneration($pixelforgeId));
                $generation->touch(); // so a still-running one waits 2 more minutes before we ask again
            } catch (PixelforgeException|ConnectionException $e) {
                $this->warn("Could not sync {$pixelforgeId}: {$e->getMessage()}");
            }
        }

        $this->info("Checked {$quiet->count()} generation(s).");

        return self::SUCCESS;
    }
}
