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
     *
     * `sent_at` is the browser's clock when it built the batch: a PC whose
     * clock is off shifts every timestamp by the same amount, so the batch is
     * moved back onto server time instead of being dropped or misplaced.
     */
    public function store(Request $request, string $StoreId)
    {
        $storeId = $this->resolveStoreId($StoreId);

        $data = $request->validate([
            'sent_at' => 'nullable|date',
            'segments' => 'required|array|min:1|max:500',
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

        // Seconds to add to the client's timestamps; a few seconds of network delay are not skew.
        $skew = isset($data['sent_at']) ? Carbon::parse($data['sent_at'])->diffInSeconds($now, false) : 0;
        $skew = abs($skew) > 5 ? (int) round($skew) : 0;

        // A bad row (station deleted meanwhile, clock skew) is skipped, not
        // fatal: failing the batch would make the browser resend it forever.
        foreach ($data['segments'] as $segment) {
            $startedAt = Carbon::parse($segment['started_at'])->utc()->addSeconds($skew);
            $endedAt = Carbon::parse($segment['ended_at'])->utc()->addSeconds($skew);
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
}
