<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Services\ScreenUsageReport;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AggregateDailyUsageCommand extends Command
{
    protected $signature = 'usage:aggregate-daily
                            {--start= : First work day (Y-m-d); default six days before --end}
                            {--end= : Last work day (Y-m-d); default the latest final one}';

    protected $description = 'Rebuild screen_usage_daily and screen_usage_daily_users for final work days, from the segments';

    public function handle(ScreenUsageReport $report): int
    {
        $tz = ScreenUsageReport::WORK_DAY_TIMEZONE;
        $latest = ScreenUsageReport::latestFinalWorkDay($tz);

        $end = $this->option('end') ?: $latest;
        if ($end > $latest) {
            // Someone may still be working a shift that started on a later day.
            $this->warn("Work days after {$latest} are not final yet; stopping there.");
            $end = $latest;
        }
        $start = $this->option('start') ?: CarbonImmutable::parse($end)->subDays(6)->toDateString();

        $stores = Store::pluck('store_number', 'id')->all();
        $storeIds = array_flip($stores);

        for ($day = CarbonImmutable::parse($start); $day->toDateString() <= $end; $day = $day->addDay()) {
            $date = $day->toDateString();
            $now = now();
            $rows = array_map(fn (array $row) => [
                'work_date' => $date,
                'store_id' => $storeIds[$row['store']],
                'station_id' => $row['station_id'],
                'user_id' => $row['user_id'],
                ...array_map(fn (string $m) => (int) round($row[$m]), array_combine(ScreenUsageReport::METRICS, ScreenUsageReport::METRICS)),
                'created_at' => $now,
                'updated_at' => $now,
            ], $report->workDayRows($stores, $date, $tz));

            $users = [];
            foreach ($report->collectWorkDay($stores, $date) as $userId => $stats) {
                $users[] = ['work_date' => $date, 'user_id' => $userId, 'stats' => json_encode($stats), 'created_at' => $now, 'updated_at' => $now];
            }

            DB::transaction(function () use ($date, $rows, $users, $now) {
                DB::table('screen_usage_daily')->where('work_date', $date)->delete();
                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table('screen_usage_daily')->insert($chunk);
                }
                DB::table('screen_usage_daily_users')->where('work_date', $date)->delete();
                foreach (array_chunk($users, 500) as $chunk) {
                    DB::table('screen_usage_daily_users')->insert($chunk);
                }
                DB::table('screen_usage_aggregated_days')->upsert(
                    [['work_date' => $date, 'created_at' => $now, 'updated_at' => $now]], ['work_date'], ['updated_at'],
                );
            });

            $this->line("{$date}: ".count($rows).' rows, '.count($users).' managers');
        }

        return self::SUCCESS;
    }
}
