<?php
namespace App\Http\Controllers;

use App\Models\ScreenUsageSegment;
use App\Models\Station;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class ScreenUsageController extends Controller
{
    protected function resolveStoreId(string $storeNumber): int
    {
        $store = Store::where('store_number', $storeNumber)->firstOrFail(['id']);
        return (int) $store->id;
    }

    /**
     * The manager's browser reports its usage segments in batches. Open
     * segments are re-sent with a later ended_at; the client-made id turns
     * that into an update. Who and which store always come from the token.
     */
    public function store(Request $request, string $StoreId)
    {
        $storeId = $this->resolveStoreId($StoreId);

        $data = $request->validate([
            'segments' => 'required|array|min:1|max:200',
            'segments.*.id' => 'required|ulid',
            'segments.*.room' => 'nullable|string',
            'segments.*.kind' => ['required', Rule::in(ScreenUsageSegment::KINDS)],
            'segments.*.state' => ['nullable', Rule::in(ScreenUsageSegment::STATES)],
            'segments.*.surface' => ['required', Rule::in(ScreenUsageSegment::SURFACES)],
            'segments.*.started_at' => 'required|date',
            'segments.*.ended_at' => 'required|date',
            'segments.*.talking_seconds' => 'nullable|integer|min:0',
            'segments.*.end_reason' => 'nullable|string|max:32',
        ]);

        $stationIds = Station::where('store_id', $storeId)->pluck('id', 'room_name');
        $now = now();
        $rows = [];

        // A bad row (station deleted meanwhile, clock skew) is skipped, not
        // fatal: failing the batch would make the browser resend it forever.
        foreach ($data['segments'] as $segment) {
            $startedAt = Carbon::parse($segment['started_at'])->utc();
            $endedAt = Carbon::parse($segment['ended_at'])->utc();
            $duration = $startedAt->diffInSeconds($endedAt, false);

            if ($duration < 0 || $duration > 86400 || $endedAt->gt($now->copy()->addMinute())) {
                continue;
            }

            $room = $segment['room'] ?? null;
            if ($segment['kind'] !== 'talk_all' && !isset($stationIds[$room])) {
                continue;
            }

            $rows[] = [
                'id' => strtolower($segment['id']),
                'store_id' => $storeId,
                'station_id' => $room !== null ? ($stationIds[$room] ?? null) : null,
                'user_id' => (int) $request->attributes->get('authz_user_id'),
                'user_name' => $request->attributes->get('authz_user_name'),
                'kind' => $segment['kind'],
                'state' => $segment['state'] ?? null,
                'surface' => $segment['surface'],
                'started_at' => $startedAt,
                'ended_at' => $endedAt,
                'talking_seconds' => min((int) ($segment['talking_seconds'] ?? 0), (int) $duration),
                'end_reason' => $segment['end_reason'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            ScreenUsageSegment::upsert($rows, ['id'], ['ended_at', 'talking_seconds', 'end_reason', 'updated_at']);
        }

        return response()->noContent();
    }

    /**
     * Raw segments overlapping [from, to] plus the store's stations. The
     * dashboard aggregates them in the viewer's own time zone.
     */
    public function index(Request $request, string $StoreId)
    {
        $storeId = $this->resolveStoreId($StoreId);

        $data = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after:from',
            'user_id' => 'nullable|integer',
        ]);

        $from = Carbon::parse($data['from'])->utc();
        $to = Carbon::parse($data['to'])->utc();
        if ($from->diffInDays($to) > 62) {
            abort(422, 'Range cannot exceed 62 days.');
        }

        $segments = ScreenUsageSegment::where('store_id', $storeId)
            ->where('started_at', '<', $to)
            ->where('ended_at', '>', $from)
            ->when($data['user_id'] ?? null, fn ($q, $userId) => $q->where('user_id', $userId))
            ->orderBy('started_at')
            ->get(['id', 'station_id', 'user_id', 'user_name', 'kind', 'state', 'surface', 'started_at', 'ended_at', 'talking_seconds', 'end_reason']);

        return response()->json([
            'stations' => Station::where('store_id', $storeId)->orderBy('id')->get(['id', 'name', 'room_name', 'type']),
            'segments' => $segments,
        ]);
    }
}
