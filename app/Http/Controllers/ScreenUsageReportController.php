<?php
namespace App\Http\Controllers;

use App\Models\ScreenUsageSegment;
use App\Models\Station;
use App\Models\Store;
use App\Services\ScreenUsageReport;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Screen Project monitoring, across stores. `stores[]` are store NUMBERS; the
 * auth server checks the caller holds `screens manager` at every one of them.
 */
class ScreenUsageReportController extends Controller
{
    public const MAX_RANGE_DAYS = 92;

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
            'tz' => 'nullable|timezone:all',
            'users' => 'nullable|array',
            'users.*' => 'integer',
        ]);

        $stores = $this->resolveStores($data['stores']);
        [$from, $to] = $this->range($data, self::MAX_RANGE_DAYS);

        $result = $report->build($stores, $from, $to, $data['users'] ?? null, $data['tz'] ?? 'UTC');

        // Everyone seen in these stores and dates, for the manager filter (ignores the filter itself).
        $result['managers'] = ScreenUsageSegment::query()
            ->whereIn('store_id', array_keys($stores))
            ->where('started_at', '<', $to)
            ->where('ended_at', '>', $from)
            ->groupBy('user_id')
            ->selectRaw('user_id, MAX(user_name) as name')
            ->orderBy('name')
            ->get()
            ->map(fn ($m) => ['user_id' => (int) $m->user_id, 'name' => $m->name ?: "#{$m->user_id}"])
            ->values();

        return response()->json($result);
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
