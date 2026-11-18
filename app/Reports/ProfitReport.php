<?php

namespace App\Reports;

use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Operator report: what users were charged, what Pixelforge charged us, and the profit,
 * per UTC day and per user, with each day checked against Pixelforge's GET /v1/usage.
 *
 * A generation belongs to the UTC day it COMPLETED (Pixelforge's completed_at),
 * because that is how /v1/usage buckets costs. Only generations Pixelforge billed
 * (provider_cost_nanos set) count, which includes the rare "we gave up but it
 * succeeded" case where the user paid nothing.
 *
 * @phpstan-type Totals array{generations: int, charged: string, provider_cost: string, profit: string}
 * @phpstan-type DayRow array{date: string, generations: int, charged: string, provider_cost: string, profit: string, pixelforge_cost: string|null, matches_pixelforge: bool|null}
 * @phpstan-type UserRow array{date: string, user: string, generations: int, charged: string, provider_cost: string, profit: string}
 */
class ProfitReport
{
    public function __construct(private readonly PixelforgeClient $pixelforge) {}

    /**
     * @return array{days: list<DayRow>, by_user: list<UserRow>, pixelforge_available: bool}
     */
    public function build(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $generations = Generation::query()
            ->with('user:id,name,email')
            ->whereNotNull('provider_cost_nanos')
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$from->startOfDay(), $to->endOfDay()])
            ->get();

        $pixelforgeCostByDay = $this->pixelforgeCostByDay($from, $to);

        return [
            'days' => $this->days($generations, $pixelforgeCostByDay, $from, $to),
            'by_user' => $this->byDayAndUser($generations),
            'pixelforge_available' => $pixelforgeCostByDay !== null,
        ];
    }

    /**
     * One row per day in the range, newest first, including days with no generations.
     *
     * @param  Collection<int, Generation>  $generations
     * @param  array<string, int>|null  $pixelforgeCostByDay
     * @return list<DayRow>
     */
    private function days(Collection $generations, ?array $pixelforgeCostByDay, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $byDay = $generations->groupBy(fn (Generation $generation): string => $this->billingDay($generation));
        $rows = [];

        foreach (CarbonPeriod::create($from, $to) as $date) {
            $day = $date->toDateString();
            $group = $byDay->get($day, new Collection);

            $ourCost = $this->sum($group, 'provider_cost_nanos');
            $theirCost = $pixelforgeCostByDay === null ? null : ($pixelforgeCostByDay[$day] ?? 0);

            $rows[] = [
                'date' => $day,
                ...$this->totals($group),
                'pixelforge_cost' => $theirCost === null ? null : $this->exact($theirCost),
                'matches_pixelforge' => $theirCost === null ? null : $ourCost === $theirCost,
            ];
        }

        return array_reverse($rows);
    }

    /**
     * @param  Collection<int, Generation>  $generations
     * @return list<UserRow>
     */
    private function byDayAndUser(Collection $generations): array
    {
        return array_values($generations
            ->groupBy(fn (Generation $generation): string => $this->billingDay($generation).'|'.$generation->user_id)
            ->map(function (Collection $group, string $key): array {
                $user = $group->first()->user;

                return [
                    'date' => explode('|', $key)[0],
                    'user' => "{$user->name} ({$user->email})",
                    ...$this->totals($group),
                ];
            })
            ->sortByDesc('date')
            ->all());
    }

    /**
     * @param  Collection<int, Generation>  $group
     * @return Totals
     */
    private function totals(Collection $group): array
    {
        $charged = $this->sum($group, 'charged_nanos');
        $cost = $this->sum($group, 'provider_cost_nanos');

        return [
            'generations' => $group->count(),
            'charged' => $this->exact($charged),
            'provider_cost' => $this->exact($cost),
            'profit' => $this->exact($charged - $cost),
        ];
    }

    /**
     * What Pixelforge billed per UTC day, in nanodollars, or null when it can't be reached.
     *
     * @return array<string, int>|null
     */
    private function pixelforgeCostByDay(CarbonImmutable $from, CarbonImmutable $to): ?array
    {
        try {
            $usage = $this->pixelforge->usage($from->toDateString(), $to->toDateString());
        } catch (Throwable) {
            return null;
        }

        $costs = [];

        foreach ($usage['data'] ?? [] as $day) {
            $costs[$day['date']] = Money::parseDollars($day['cost_usd']);
        }

        return $costs;
    }

    private function billingDay(Generation $generation): string
    {
        return $generation->completed_at?->utc()->toDateString() ?? '';
    }

    /**
     * Integer sum of a nanodollar column (never a float).
     *
     * @param  Collection<int, Generation>  $group
     */
    private function sum(Collection $group, string $column): int
    {
        return $group->reduce(fn (int $total, Generation $generation): int => $total + (int) $generation->getAttribute($column), 0);
    }

    /** Exact 9-decimal dollars for the report: 420_393_750 => "$0.420393750". */
    private function exact(int $nanos): string
    {
        return ($nanos < 0 ? '-$' : '$').Money::toDecimalString(abs($nanos));
    }
}
