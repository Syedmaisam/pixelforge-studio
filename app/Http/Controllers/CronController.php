<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;

/**
 * GET /cron/run: the scheduler and the queue worker, over HTTP.
 *
 * For hosting (like Plesk) where a scheduled task can fetch a URL but running
 * artisan directly is awkward. Call it once a minute. Each call:
 *
 *   1. runs `schedule:run` (which runs pixelforge:sync when it is due), then
 *   2. works through the queue (submit jobs and their retries) for up to
 *      QUEUE_SECONDS, then stops. Jobs released with a delay are picked up
 *      by a later call.
 *
 * It needs the CRON_TOKEN secret, sent as an "X-Cron-Token" header or a
 * "?token=" query parameter. Without a valid token, or when no token is
 * configured, it answers 404 so the URL looks like it doesn't exist.
 */
class CronController extends Controller
{
    /** Stay well inside typical PHP and web server time limits (often 30-60 s). */
    private const QUEUE_SECONDS = 25;

    public function __invoke(Request $request): Response
    {
        abort_unless($this->hasValidToken($request), 404);

        ignore_user_abort(true); // keep going if the caller stops waiting
        set_time_limit(self::QUEUE_SECONDS + 30);

        Artisan::call('schedule:run');
        $scheduleOutput = trim(Artisan::output());

        Artisan::call('queue:work', [
            '--stop-when-empty' => true,
            '--max-time' => self::QUEUE_SECONDS,
            '--sleep' => 1,
        ]);
        $queueOutput = trim(Artisan::output());

        return response(
            "schedule:run\n{$scheduleOutput}\n\nqueue:work\n{$queueOutput}\n",
            200,
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
    }

    private function hasValidToken(Request $request): bool
    {
        $expected = (string) config('services.cron.token');
        $given = (string) ($request->header('X-Cron-Token') ?? $request->query('token', ''));

        return $expected !== '' && hash_equals($expected, $given);
    }
}
