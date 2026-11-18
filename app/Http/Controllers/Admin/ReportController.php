<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Reports\ProfitReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The admin profit report for the last N UTC days (default 14, at most 92,
 * which is the longest range GET /v1/usage accepts).
 */
class ReportController extends Controller
{
    public function __invoke(Request $request, ProfitReport $report): Response
    {
        $days = max(1, min($request->integer('days', 14), 92));

        $to = CarbonImmutable::now('UTC')->startOfDay();
        $from = $to->subDays($days - 1);

        return Inertia::render('admin/Report', [
            ...$report->build($from, $to),
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $days],
        ]);
    }
}
