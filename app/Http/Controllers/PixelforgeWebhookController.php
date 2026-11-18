<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Pixelforge\WebhookSignature;
use App\Services\GenerationStateUpdater;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * POST /webhooks/pixelforge
 *
 * 1. Verify the signature against the RAW body (before any JSON decoding).
 * 2. In ONE database transaction:
 *      - insert the event id (unique index); if it already exists, it's a duplicate: stop.
 *      - apply the generation object through GenerationStateUpdater.
 * 3. Answer 204.
 *
 * Why one transaction: "we stored the event" and "we applied the event" can never
 * disagree. If applying throws, the event row rolls back too, we answer 500, and
 * Pixelforge's retry is processed normally instead of being skipped as a duplicate.
 * The work is a few small queries, well inside Pixelforge's 10 second limit.
 */
class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, GenerationStateUpdater $updater): Response
    {
        $rawBody = $request->getContent();

        $isValid = WebhookSignature::isValid(
            header: (string) $request->header('Pixelforge-Signature', ''),
            rawBody: $rawBody,
            secret: (string) config('services.pixelforge.webhook_secret'),
            now: time(),
        );

        if (! $isValid) {
            return response('Invalid signature.', 400);
        }

        $event = json_decode($rawBody, true);

        if (! is_array($event) || ! isset($event['id'], $event['type'])) {
            return response('Not a Pixelforge event.', 400);
        }

        $remote = $event['data']['object'] ?? null;

        DB::transaction(function () use ($event, $remote, $updater) {
            $isNewEvent = WebhookEvent::query()->insertOrIgnore([
                'event_id' => $event['id'],
                'type' => $event['type'],
                'pixelforge_generation_id' => $remote['id'] ?? null,
                'payload' => json_encode($event),
                'created_at' => now(),
                'updated_at' => now(),
            ]) === 1;

            if (! $isNewEvent) {
                return; // duplicate delivery: already applied once, never apply again
            }

            if ($event['type'] === 'ping' || ! is_array($remote)) {
                return;
            }

            $generation = Generation::findForPixelforge($remote);

            if ($generation === null) {
                Log::warning('Pixelforge webhook for an unknown generation', ['event' => $event['id']]);

                return;
            }

            $updater->apply($generation, $remote);
        });

        return response()->noContent();
    }
}
