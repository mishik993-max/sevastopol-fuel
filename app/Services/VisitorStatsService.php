<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class VisitorStatsService
{
    public function record(string $visitorId): void
    {
        $visitorId = strtolower(trim($visitorId));
        $date = Carbon::now(config('app.timezone'))->toDateString();
        $now = now();

        DB::transaction(function () use ($date, $visitorId, $now) {
            $this->ensureDailyRow($date, $now);

            $isNewVisitor = DB::table('visitor_daily_uniques')->insertOrIgnore([
                'date' => $date,
                'visitor_id' => $visitorId,
                'first_seen_at' => $now,
            ]) === 1;

            $updates = [
                'total_visits' => DB::raw('total_visits + 1'),
                'updated_at' => $now,
            ];

            if ($isNewVisitor) {
                $updates['unique_visitors'] = DB::raw('unique_visitors + 1');
            }

            DB::table('visitor_daily_stats')
                ->where('date', $date)
                ->update($updates);
        });
    }

    /** @return array{today: int, yesterday: int} */
    public function headlineCounts(): array
    {
        $tz = config('app.timezone');
        $today = Carbon::now($tz)->toDateString();
        $yesterday = Carbon::now($tz)->subDay()->toDateString();

        $rows = DB::table('visitor_daily_stats')
            ->whereIn('date', [$today, $yesterday])
            ->pluck('unique_visitors', 'date');

        return [
            'today' => (int) ($rows[$today] ?? 0),
            'yesterday' => (int) ($rows[$yesterday] ?? 0),
        ];
    }

    /** @return list<array{date: string, date_label: string, unique_visitors: int, total_visits: int}> */
    public function dailyBreakdown(int $days = 30): array
    {
        $tz = config('app.timezone');
        $end = Carbon::now($tz)->startOfDay();
        $start = $end->copy()->subDays(max(1, $days) - 1);

        return DB::table('visitor_daily_stats')
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->where('total_visits', '>', 0)
            ->orderBy('date')
            ->get()
            ->map(function ($row) use ($tz) {
                $day = Carbon::parse((string) $row->date, $tz);

                return [
                    'date' => (string) $row->date,
                    'date_label' => $day->format('d.m.Y'),
                    'unique_visitors' => (int) $row->unique_visitors,
                    'total_visits' => (int) $row->total_visits,
                ];
            })
            ->values()
            ->all();
    }

    /** @return array{period_days: int, days_with_visits: int, total_visits: int, tracking_since: ?string} */
    public function periodSummary(int $days = 30): array
    {
        $tz = config('app.timezone');
        $end = Carbon::now($tz)->startOfDay();
        $start = $end->copy()->subDays(max(1, $days) - 1);

        $stats = DB::table('visitor_daily_stats')
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()]);

        return [
            'period_days' => $days,
            'days_with_visits' => (int) (clone $stats)->where('total_visits', '>', 0)->count(),
            'total_visits' => (int) (clone $stats)->sum('total_visits'),
            'tracking_since' => DB::table('visitor_daily_stats')
                ->where('total_visits', '>', 0)
                ->min('date'),
        ];
    }

    private function ensureDailyRow(string $date, $now): void
    {
        DB::table('visitor_daily_stats')->insertOrIgnore([
            'date' => $date,
            'unique_visitors' => 0,
            'total_visits' => 0,
            'updated_at' => $now,
        ]);
    }
}
