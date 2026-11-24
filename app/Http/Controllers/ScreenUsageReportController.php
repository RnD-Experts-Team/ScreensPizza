<?php
namespace App\Http\Controllers;

use App\Models\ScreenUsageSegment;
use App\Models\Station;
use App\Models\Store;
use App\Models\User;
use App\Services\ScreenUsageReport;
use Illuminate\Http\Request;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

/**
 * Screen Project monitoring, across stores. `stores[]` are store NUMBERS; the
 * auth server checks the caller holds `screens manager` at every one of them.
 */
class ScreenUsageReportController extends Controller
{
    /** CSV header => dailyRows() key; durations go out in hours. */
    private const EXPORT_COLUMNS = [
        'Date' => 'date',
        'Store' => 'store',
        'Manager ID' => 'user_id',
        'Manager' => 'name',
        'Connected hours' => 'on_screen',
        'Present hours' => 'present',
        'Away hours' => 'away',
        'No face hours' => 'no_face',
        'Covered hours' => 'covered',
        'Camera off hours' => 'camera_off',
        'Mic open hours' => 'mic_open',
        'Talking hours' => 'talking',
        'Silent hours' => 'silent',
        'Dead mic hours' => 'dead_mic',
        'Talk to all hours' => 'talk_all',
        'Mini player hours' => 'pip',
        'Visits' => 'visits',
        'Kicks' => 'kicks',
    ];

    /** The per-station export: the same columns, cut by the station in the main tile. */
    private const STATION_EXPORT_COLUMNS = [
        'Date' => 'date',
        'Store' => 'store',
        'Station ID' => 'station_id',
        'Station' => 'station',
        'Manager ID' => 'user_id',
        'Manager' => 'name',
        'Connected hours' => 'on_screen',
        'Present hours' => 'present',
        'Away hours' => 'away',
        'No face hours' => 'no_face',
        'Covered hours' => 'covered',
        'Camera off hours' => 'camera_off',
        'Mic open hours' => 'mic_open',
        'Talking hours' => 'talking',
        'Silent hours' => 'silent',
        'Dead mic hours' => 'dead_mic',
        'Talk to all hours' => 'talk_all',
        'Mini player hours' => 'pip',
        'Visits' => 'visits',
        'Kicks' => 'kicks',
    ];

    private const NOT_HOURS = ['date', 'store', 'station_id', 'station', 'user_id', 'name', 'visits', 'kicks'];

    /** @return array<int, string> store id => store number */
    protected function resolveStores(array $numbers): array
    {
        $stores = Store::whereIn('store_number', $numbers)->pluck('store_number', 'id')->all();
        if ($stores === []) {
            abort(404, 'None of the stores were found.');
        }

        return $stores;
    }

    protected function range(array $data, int $maxDays): array
    {
        $from = Carbon::parse($data['from'])->utc();
        $to = Carbon::parse($data['to'])->utc();
        if ($from->diffInDays($to) > $maxDays) {
            abort(422, "Range cannot exceed {$maxDays} days.");
        }

        return [$from, $to];
    }

    /** The aggregated report: KPIs, managers, stores, screens, days, weekday × hour. */
    public function report(Request $request, ScreenUsageReport $report)
    {
        $data = $request->validate([
            'stores' => 'required|array|min:1|max:500',
            'stores.*' => 'string',
            'from' => 'required|date',
            'to' => 'required|date|after:from',
            'tz' => 'nullable|timezone:all_with_bc',
            'users' => 'nullable|array',
            'users.*' => 'integer',
        ]);

        $stores = $this->resolveStores($data['stores']);
        $from = Carbon::parse($data['from'])->utc();
        $to = Carbon::parse($data['to'])->utc();

        $tz = $data['tz'] ?? 'UTC';
        // Query-string ids arrive as strings.
        $userIds = isset($data['users']) ? array_map('intval', $data['users']) : null;

        // Work days, stored ones read from the daily aggregate (see build()); its `managers` is
        // everyone seen in these stores and days, for the manager filter (which it ignores).
        $result = $report->build($stores, $from, $to, $userIds, $tz);

        // Each screen's own connected / present / talking time: a manager's time goes to the
        // screen in their main tile at that moment, as in the per-station export.
        $byStation = [];
        foreach ($this->workDayRows($report, $stores, $from, $to, $tz) as $row) {
            if ($row['station_id'] === null || ($userIds && !in_array($row['user_id'], $userIds, true))) {
                continue;
            }
            foreach (['connected' => 'on_screen', 'present' => 'present', 'talking' => 'talking'] as $key => $column) {
                $byStation[$row['station_id']][$key] = ($byStation[$row['station_id']][$key] ?? 0) + $row[$column];
            }
        }
        $result['screens'] = array_map(fn (array $screen) => $screen + [
            'connected' => (int) round($byStation[$screen['station_id']]['connected'] ?? 0),
            'present' => (int) round($byStation[$screen['station_id']]['present'] ?? 0),
            'talking' => (int) round($byStation[$screen['station_id']]['talking'] ?? 0),
        ], $result['screens']);

        return response()->json($result);
    }

    /**
     * ScreenUsageReport::workDays() rows for the work days a [from, to) range covers in the
     * viewer's clock (ScreenUsageReport::workDates()).
     *
     * @param  array<int, string>  $stores  store id => store number
     */
    private function workDayRows(ScreenUsageReport $report, array $stores, Carbon $from, Carbon $to, string $tz): array
    {
        $dates = ScreenUsageReport::workDates($from, $to, $tz);

        return $dates === null ? [] : $report->workDays($stores, $dates[0], $dates[1]);
    }

    /**
     * "Boots on the Ground" for the store dashboards (DSPR V1, Business Reports): who was on
     * Screen Project at these stores, and their connected, present and talking time in total and
     * per station (the station in their main tile at that moment). Only what those cards show, and
     * read with the stores' report permission, not `screens manager`: every manager at a store sees
     * the store's managers, themselves and their partners. Every standard station is listed, so a
     * station nobody watched shows as zero. Seconds; the work days the range covers in `tz`,
     * stored ones read from the daily aggregate.
     */
    public function bootsOnTheGround(Request $request, ScreenUsageReport $report)
    {
        $data = $request->validate([
            'stores' => 'required|array|min:1|max:500',
            'stores.*' => 'string',
            'from' => 'required|date',
            'to' => 'required|date|after:from',
            'tz' => 'nullable|timezone:all_with_bc',
        ]);

        $stores = $this->resolveStores($data['stores']);
        $from = Carbon::parse($data['from'])->utc();
        $to = Carbon::parse($data['to'])->utc();

        $metrics = ['connected' => 'on_screen', 'present' => 'present', 'talking' => 'talking'];
        $zero = ['connected' => 0, 'present' => 0, 'talking' => 0];
        $totals = $zero;
        $managers = [];
        foreach ($this->workDayRows($report, $stores, $from, $to, $data['tz'] ?? 'UTC') as $row) {
            $id = (int) $row['user_id'];
            $managers[$id] ??= ['user_id' => $id, 'name' => $row['name'], 'stores' => [], ...$zero, 'stations' => []];
            $managers[$id]['stores'][$row['store']] = true;
            foreach ($metrics as $key => $column) {
                $totals[$key] += $row[$column];
                $managers[$id][$key] += $row[$column];
                if ($row['station_id'] !== null) {
                    $managers[$id]['stations'][$row['station_id']][$key] = ($managers[$id]['stations'][$row['station_id']][$key] ?? 0) + $row[$column];
                }
            }
        }

        $round = fn (array $m) => array_map(fn ($v) => (int) round($v), array_intersect_key($m, $zero));
        $managers = array_map(fn (array $m) => [
            'user_id' => $m['user_id'],
            'name' => $m['name'],
            // A numeric store number became an int as an array key.
            'stores' => array_map('strval', array_keys($m['stores'])),
            ...$round($m),
            'stations' => array_map(
                fn (int $stationId, array $s) => ['station_id' => $stationId, ...$round($s + $zero)],
                array_keys($m['stations']),
                $m['stations'],
            ),
        ], array_values($managers));
        usort($managers, fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));

        $stations = Station::whereIn('store_id', array_keys($stores))
            ->where('type', Station::TYPE_STANDARD)
            ->orderBy('store_id')->orderBy('name')
            ->get(['id', 'store_id', 'name'])
            ->map(fn (Station $s) => ['station_id' => $s->id, 'name' => $s->name, 'store' => $stores[$s->store_id] ?? null])
            ->values();

        return response()->json([
            'range' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String(), 'tz' => $data['tz'] ?? 'UTC'],
            'totals' => $round($totals),
            'managers' => $managers,
            'stations' => $stations,
        ]);
    }

    /**
     * The monitoring numbers as CSV for Excel: one row per local day × store × manager over
     * start_date..end_date (inclusive). `stores` are store numbers, comma-separated or stores[];
     * none means every store.
     */
    public function export(Request $request, ScreenUsageReport $report)
    {
        return $this->csv($request, $report, false, self::EXPORT_COLUMNS, 'screen-monitoring');
    }

    /**
     * The same rows cut by the station in the manager's main tile (plus a "No main tile" row),
     * so each manager's station rows add up to their row in export().
     */
    public function exportStations(Request $request, ScreenUsageReport $report)
    {
        return $this->csv($request, $report, true, self::STATION_EXPORT_COLUMNS, 'screen-monitoring-stations');
    }

    /** The Boots on the Ground report's station columns => the stations each one adds up. */
    private const BOOTS_COLUMNS = [
        'landing' => ['Landing'],
        'making' => ['Making'],
        'boh' => ['BOH'],
        'register_1' => ['Register'],
        'register_2' => ['Register2'],
        'others' => ['No main tile', 'Phone', 'Making - Sales', 'Inner Drive-Thru'],
    ];

    /**
     * GET /usage/export/boots-on-the-ground?date=YYYY-MM-DD[&stores=a,b][&tz=]
     *
     * The Boots on the Ground workbook, ready to show: per store, video (present) and voice
     * (talking) hours for the week, period, quarter and year up to `date`, each against the
     * previous one and the same one last year, plus the week's detail by station and by day.
     * The ranges are LC_PIZZA_DATA's /reports/phone-and-adjusted-sales ones: weeks start on
     * Tuesday, the fiscal year on the Tuesday of the week holding Jan 1, periods are 4 weeks,
     * quarters 3-3-3-4 periods, and a comparison covers as many days as the current range.
     */
    public function bootsOnTheGroundExport(Request $request, ScreenUsageReport $report)
    {
        $validator = Validator::make($request->query(), [
            'date' => 'required|date_format:Y-m-d',
            'stores' => 'nullable',
            'tz' => 'nullable|timezone:all_with_bc',
        ]);
        abort_if($validator->fails(), 422, $validator->errors()->first());
        $data = $validator->validated();

        $numbers = $data['stores'] ?? [];
        $numbers = array_values(array_filter(array_map('trim', is_array($numbers) ? $numbers : explode(',', (string) $numbers))));
        $stores = $numbers === [] ? Store::pluck('store_number', 'id')->all() : $this->resolveStores($numbers);
        $tz = $data['tz'] ?? ScreenUsageReport::WORK_DAY_TIMEZONE;

        $day = CarbonImmutable::parse($data['date'])->startOfDay();
        $weekStart = $day->startOfWeek(CarbonInterface::TUESDAY);
        $fiscalYear = $this->fiscalYearOf($day);
        $yearStart = $this->fiscalYearStart($fiscalYear);
        $prevYearStart = $this->fiscalYearStart($fiscalYear - 1);
        $weekIdx = intdiv((int) $yearStart->diffInDays($weekStart), 7);
        $periodIdx = intdiv($weekIdx, 4);
        $periodStart = $yearStart->addWeeks($periodIdx * 4);
        $quarterStartIdx = $periodIdx < 3 ? 0 : ($periodIdx < 6 ? 3 : ($periodIdx < 9 ? 6 : 9));
        $quarterStart = $yearStart->addWeeks($quarterStartIdx * 4);

        // scope => [current start, previous start, last year's start, last year's key]
        $scopes = [
            'week' => [$weekStart, $weekStart->subWeeks(1), $weekStart->subWeeks(52), 'same_week_last_year'],
            'period' => [$periodStart, $periodStart->subWeeks(4), $prevYearStart->addWeeks($periodIdx * 4), 'same_period_last_year'],
            'quarter' => [$quarterStart, $quarterStart->subWeeks($quarterStartIdx === 0 ? 16 : 12), $prevYearStart->addWeeks($quarterStartIdx * 4), 'same_quarter_last_year'],
            'year' => [$yearStart, $prevYearStart, null, null],
        ];

        // store => date => sums, every day from last fiscal year's start to `date`, and the week's
        // rows by station. Work days in the stores' clock: the totals summed by the database from
        // the daily aggregate, the week's few rows read whole; another tz keeps that zone's days.
        $daily = [];
        $weekRows = [];
        if ($tz === ScreenUsageReport::WORK_DAY_TIMEZONE) {
            foreach ($report->workDayTotals($stores, $prevYearStart->toDateString(), $day->toDateString()) as $store => $dates) {
                foreach ($dates as $date => $m) {
                    $daily[(string) $store][$date] = ['video' => $m['present'], 'voice' => $m['talking']];
                }
            }
            foreach ($report->workDays($stores, $weekStart->toDateString(), $day->toDateString()) as $row) {
                $weekRows[(string) $row['store']][] = $row;
            }
        } else {
            $from = Carbon::parse($prevYearStart->toDateString(), $tz)->startOfDay()->utc();
            $to = Carbon::parse($day->toDateString(), $tz)->addDay()->startOfDay()->utc();
            foreach ($report->dailyRows($stores, $from, $to, $tz, true) as $row) {
                $store = (string) $row['store'];
                $daily[$store][$row['date']]['video'] = ($daily[$store][$row['date']]['video'] ?? 0) + $row['present'];
                $daily[$store][$row['date']]['voice'] = ($daily[$store][$row['date']]['voice'] ?? 0) + $row['talking'];
                if ($row['date'] >= $weekStart->toDateString()) {
                    $weekRows[$store][] = $row;
                }
            }
        }

        $hours = fn (float $seconds) => round($seconds / 3600, 2);
        $sum = function (string $store, CarbonImmutable $start, CarbonImmutable $end) use ($daily, $hours): array {
            $video = $voice = 0;
            foreach ($daily[$store] ?? [] as $date => $m) {
                if ($date >= $start->toDateString() && $date <= $end->toDateString()) {
                    $video += $m['video'] ?? 0;
                    $voice += $m['voice'] ?? 0;
                }
            }

            return ['start' => $start->toDateString(), 'end' => $end->toDateString(), 'video_hours' => $hours($video), 'voice_hours' => $hours($voice)];
        };

        $numbers = array_values($stores);
        sort($numbers);
        $byStore = [];
        foreach ($numbers as $store) {
            $store = (string) $store;
            $out = [];
            foreach ($scopes as $scope => [$start, $prevStart, $lastYearStart, $lastYearKey]) {
                $days = (int) $start->diffInDays($day);
                $out[$scope] = [
                    'current' => $sum($store, $start, $day),
                    'previous' => $sum($store, $prevStart, $prevStart->addDays($days)),
                ];
                if ($lastYearStart !== null) {
                    $out[$scope][$lastYearKey] = $sum($store, $lastYearStart, $lastYearStart->addDays($days));
                }
            }

            // The week's detail: the report's station columns, every station by name, and each day.
            $columns = array_fill_keys(array_keys(self::BOOTS_COLUMNS), ['video' => 0, 'voice' => 0]);
            $stations = [];
            foreach ($weekRows[$store] ?? [] as $row) {
                $name = (string) $row['station'];
                $stations[$name]['video'] = ($stations[$name]['video'] ?? 0) + $row['present'];
                $stations[$name]['voice'] = ($stations[$name]['voice'] ?? 0) + $row['talking'];
                foreach (self::BOOTS_COLUMNS as $column => $names) {
                    if (in_array($name, $names, true)) {
                        $columns[$column]['video'] += $row['present'];
                        $columns[$column]['voice'] += $row['talking'];
                    }
                }
            }
            ksort($stations);
            $toHours = fn (array $m) => ['video_hours' => $hours($m['video'] ?? 0), 'voice_hours' => $hours($m['voice'] ?? 0)];
            $weekDays = [];
            for ($d = $weekStart; $d->lte($day); $d = $d->addDay()) {
                $weekDays[] = ['date' => $d->toDateString(), ...$toHours($daily[$store][$d->toDateString()] ?? [])];
            }

            $out['week_detail'] = [
                'overall' => ['video_hours' => $out['week']['current']['video_hours'], 'voice_hours' => $out['week']['current']['voice_hours']],
                'columns' => array_map($toHours, $columns),
                'stations' => array_map($toHours, $stations),
                'days' => $weekDays,
            ];
            $byStore[$store] = $out;
        }

        return response()->json([
            'filtering' => [
                'date' => $day->toDateString(),
                'tz' => $tz,
                'fiscal_year' => $fiscalYear,
                'week_number' => $weekIdx + 1,
                'week_start' => $weekStart->toDateString(),
                'period_number' => $periodIdx + 1,
                'period_start' => $periodStart->toDateString(),
                'quarter_number' => intdiv($quarterStartIdx, 3) + 1,
                'quarter_start' => $quarterStart->toDateString(),
                'year_start' => $yearStart->toDateString(),
                'stores' => $numbers,
                'store_count' => count($numbers),
            ],
            'by_store' => $byStore,
        ]);
    }

    /**
     * GET /usage/export/daily?date=YYYY-MM-DD[&stores=a,b]
     *
     * One work day, one row per manager: name, email, present and talking hours in total and
     * on each station (the one in their main tile; "No main tile" for the rest, so the stations
     * add up to the total). The day is shift-based in the stores' clock: every shift that
     * started on `date` counts whole, overnight included (ScreenUsageReport::workDayRows).
     * A stored day is read from the daily aggregate, any other from the segments; `final` says
     * whether somebody may still be working a shift that started on it.
     */
    public function daily(Request $request, ScreenUsageReport $report)
    {
        $validator = Validator::make($request->query(), [
            'date' => 'required|date_format:Y-m-d',
            'stores' => 'nullable',
        ]);
        abort_if($validator->fails(), 422, $validator->errors()->first());
        $data = $validator->validated();

        $numbers = $data['stores'] ?? [];
        $numbers = array_values(array_filter(array_map('trim', is_array($numbers) ? $numbers : explode(',', (string) $numbers))));
        $stores = $numbers === [] ? Store::pluck('store_number', 'id')->all() : $this->resolveStores($numbers);

        $tz = ScreenUsageReport::WORK_DAY_TIMEZONE;
        $date = $data['date'];
        $rows = $report->workDays($stores, $date, $date);

        $users = User::whereIn('id', array_unique(array_column($rows, 'user_id')))->get(['id', 'name', 'email'])->keyBy('id');
        $hours = fn (float $seconds) => round($seconds / 3600, 2);

        $managers = [];
        foreach ($rows as $row) {
            $id = $row['user_id'];
            $m = &$managers[$id];
            $m ??= ['user_id' => $id, 'name' => $users[$id]->name ?? $row['name'], 'email' => $users[$id]->email ?? null,
                'present' => 0, 'talking' => 0, 'stations' => []];
            $m['present'] += $row['present'];
            $m['talking'] += $row['talking'];
            $m['stations'][] = [
                'store' => (string) $row['store'],
                'station_id' => $row['station_id'],
                'station' => $row['station'],
                'present_hours' => $hours($row['present']),
                'talking_hours' => $hours($row['talking']),
            ];
            unset($m);
        }

        $managers = array_map(function (array $m) use ($hours) {
            usort($m['stations'], fn (array $a, array $b) => [$a['store'], $a['station']] <=> [$b['store'], $b['station']]);

            return ['user_id' => $m['user_id'], 'name' => $m['name'], 'email' => $m['email'],
                'present_hours' => $hours($m['present']), 'talking_hours' => $hours($m['talking']), 'stations' => $m['stations']];
        }, array_values($managers));
        usort($managers, fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));

        return response()->json([
            'date' => $date,
            'tz' => $tz,
            'final' => $date <= ScreenUsageReport::latestFinalWorkDay($tz),
            'managers' => $managers,
        ]);
    }

    /** As LC_PIZZA_DATA: the fiscal year starts on the Tuesday of the week holding Jan 1. */
    private function fiscalYearStart(int $year): CarbonImmutable
    {
        return CarbonImmutable::create($year, 1, 1)->startOfWeek(CarbonInterface::TUESDAY);
    }

    private function fiscalYearOf(CarbonImmutable $date): int
    {
        $y = $date->year;
        if ($date->lt($this->fiscalYearStart($y))) {
            return $y - 1;
        }

        return $date->gte($this->fiscalYearStart($y + 1)) ? $y + 1 : $y;
    }

    /** @param  array<string, string>  $columns  CSV header => dailyRows() key */
    private function csv(Request $request, ScreenUsageReport $report, bool $byStation, array $columns, string $name)
    {
        $validator = Validator::make($request->query(), [
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date',
            'stores' => 'nullable',
            'tz' => 'nullable|timezone:all_with_bc',
        ]);
        // Excel asks without Accept: application/json, so a failed validate() would redirect to "/".
        abort_if($validator->fails(), 422, $validator->errors()->first());
        $data = $validator->validated();

        $numbers = $data['stores'] ?? [];
        $numbers = array_values(array_filter(array_map('trim', is_array($numbers) ? $numbers : explode(',', (string) $numbers))));
        $stores = $numbers === [] ? Store::pluck('store_number', 'id')->all() : $this->resolveStores($numbers);

        // In the stores' clock the days are work days (stored ones read from the daily
        // aggregate); in any other zone asked for, that zone's midnight-to-midnight days.
        $tz = $data['tz'] ?? ScreenUsageReport::WORK_DAY_TIMEZONE;
        if ($tz === ScreenUsageReport::WORK_DAY_TIMEZONE) {
            $rows = $report->workDays($stores, $data['start_date'], $data['end_date'], $byStation);
        } else {
            $from = Carbon::parse($data['start_date'], $tz)->startOfDay()->utc();
            $to = Carbon::parse($data['end_date'], $tz)->addDay()->startOfDay()->utc();
            $rows = $report->dailyRows($stores, $from, $to, $tz, $byStation);
        }

        return response()->streamDownload(function () use ($rows, $columns) {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_keys($columns), escape: '');
            foreach ($rows as $row) {
                fputcsv($out, array_map(
                    fn (string $key) => in_array($key, self::NOT_HOURS, true) ? $row[$key] : round($row[$key] / 3600, 4),
                    $columns,
                ), escape: '');
            }
            fclose($out);
        }, "{$name}_{$data['start_date']}_{$data['end_date']}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** One manager's raw segments over (at most) two days — the day timeline. */
    public function timeline(Request $request)
    {
        $data = $request->validate([
            'stores' => 'required|array|min:1|max:500',
            'stores.*' => 'string',
            'user_id' => 'required|integer',
            'from' => 'required|date',
            'to' => 'required|date|after:from',
        ]);

        $stores = $this->resolveStores($data['stores']);
        [$from, $to] = $this->range($data, 2);

        $segments = ScreenUsageSegment::whereIn('store_id', array_keys($stores))
            ->where('user_id', $data['user_id'])
            ->where('started_at', '<', $to)
            ->where('ended_at', '>', $from)
            ->orderBy('started_at')
            ->get(['id', 'store_id', 'station_id', 'kind', 'state', 'surface', 'started_at', 'ended_at', 'talking_seconds', 'end_reason']);

        $stations = Station::whereIn('id', $segments->pluck('station_id')->filter()->unique())->pluck('name', 'id');

        return response()->json([
            'segments' => $segments->map(fn ($s) => [
                'id' => $s->id,
                'store' => $stores[$s->store_id] ?? null,
                'station' => $s->station_id ? ($stations[$s->station_id] ?? "#{$s->station_id}") : null,
                'kind' => $s->kind,
                'state' => $s->state,
                'surface' => $s->surface,
                'started_at' => $s->started_at->toIso8601String(),
                'ended_at' => $s->ended_at->toIso8601String(),
                'talking_seconds' => $s->talking_seconds,
                'end_reason' => $s->end_reason,
            ])->values(),
        ]);
    }
}
