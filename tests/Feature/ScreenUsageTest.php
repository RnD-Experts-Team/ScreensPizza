<?php

namespace Tests\Feature;

use App\Models\ScreenUsageSegment;
use App\Models\Station;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ScreenUsageTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Station $station;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.auth_server.base_url' => 'http://auth.test',
            'services.auth_server.verify_path' => '/verify',
            'services.auth_server.service_name' => 'Screens',
            'services.auth_server.call_token' => 'call-token',
        ]);

        Http::fake([
            'auth.test/*' => Http::response([
                'active' => true,
                'subject_type' => 'user',
                'user' => ['id' => 42, 'name' => 'Maria Manager'],
                'ext' => ['authorized' => true],
            ]),
        ]);

        $this->store = Store::create(['id' => 7, 'store_number' => '1001']);
        $this->station = Station::create([
            'store_id' => 7,
            'name' => 'Make line',
            'room_name' => 'make-line-1001',
            'type' => Station::TYPE_STANDARD,
        ]);
    }

    private function segment(array $overrides = []): array
    {
        return array_merge([
            'id' => (string) Str::ulid(),
            'room' => 'make-line-1001',
            'kind' => 'camera',
            'state' => 'visible',
            'surface' => 'page',
            'started_at' => now()->subMinutes(10)->toIso8601String(),
            'ended_at' => now()->subMinutes(5)->toIso8601String(),
        ], $overrides);
    }

    private function report(array $segments)
    {
        return $this->withToken('user-token')->postJson('/api/1001/usage/segments', ['segments' => $segments]);
    }

    public function test_records_segments_with_user_from_the_token(): void
    {
        $this->report([$this->segment(['user_id' => 999, 'user_name' => 'Spoofed'])])->assertNoContent();

        $row = ScreenUsageSegment::sole();
        $this->assertSame(42, (int) $row->user_id);
        $this->assertSame('Maria Manager', $row->user_name);
        $this->assertSame(7, (int) $row->store_id);
        $this->assertSame($this->station->id, (int) $row->station_id);
    }

    public function test_resending_an_open_segment_extends_it(): void
    {
        $segment = $this->segment(['kind' => 'mic', 'state' => 'open', 'talking_seconds' => 10]);
        $this->report([$segment])->assertNoContent();

        $later = now()->subMinute()->toIso8601String();
        $this->report([array_merge($segment, ['ended_at' => $later, 'talking_seconds' => 60])])->assertNoContent();

        $row = ScreenUsageSegment::sole();
        $this->assertSame(60, $row->talking_seconds);
        $this->assertEqualsWithDelta(now()->subMinute()->timestamp, $row->ended_at->timestamp, 2);
    }

    public function test_skips_rooms_of_other_stores_and_bad_ranges_but_keeps_the_rest(): void
    {
        $this->report([
            $this->segment(['room' => 'someone-elses-room']),
            $this->segment(['ended_at' => now()->subMinutes(20)->toIso8601String()]),
            $this->segment(['ended_at' => now()->addHour()->toIso8601String()]),
            $this->segment(['kind' => 'main', 'state' => null]),
        ])->assertNoContent();

        $this->assertSame('main', ScreenUsageSegment::sole()->kind);
    }

    public function test_shifts_a_batch_from_a_pc_with_a_wrong_clock_onto_server_time(): void
    {
        // The PC's clock runs 2 hours fast: without the shift this segment ends "in the future" and is dropped.
        $fast = now()->addHours(2);
        $this->withToken('user-token')->postJson('/api/1001/usage/segments', [
            'sent_at' => $fast->toIso8601String(),
            'segments' => [$this->segment([
                'started_at' => $fast->copy()->subMinutes(10)->toIso8601String(),
                'ended_at' => $fast->toIso8601String(),
            ])],
        ])->assertNoContent();

        $row = ScreenUsageSegment::sole();
        $this->assertEqualsWithDelta(now()->timestamp, $row->ended_at->timestamp, 3);
        $this->assertEqualsWithDelta(now()->subMinutes(10)->timestamp, $row->started_at->timestamp, 3);
    }

    public function test_accepts_the_no_face_camera_state(): void
    {
        $this->report([$this->segment(['state' => 'no_face'])])->assertNoContent();

        $this->assertSame('no_face', ScreenUsageSegment::sole()->state);
    }

    public function test_rejects_malformed_payloads(): void
    {
        $this->report([$this->segment(['kind' => 'nap'])])->assertUnprocessable();
        $this->report([$this->segment(['id' => 'not-a-ulid'])])->assertUnprocessable();
    }

    public function test_talk_to_all_has_no_station(): void
    {
        $this->report([$this->segment(['kind' => 'talk_all', 'state' => null, 'room' => null])])->assertNoContent();

        $this->assertNull(ScreenUsageSegment::sole()->station_id);
    }

    /** Writes rows straight into the table for report tests: [kind, state, from-minute, to-minute, extra]. */
    private function seedSegments(int $userId, string $name, array $rows, ?Carbon $base = null): void
    {
        $base ??= Carbon::parse('2026-10-12 08:00:00', 'UTC');
        foreach ($rows as [$kind, $state, $a, $b, $extra]) {
            ScreenUsageSegment::create(array_merge([
                'id' => (string) Str::ulid(),
                'store_id' => 7,
                'station_id' => $this->station->id,
                'user_id' => $userId,
                'user_name' => $name,
                'kind' => $kind,
                'state' => $state,
                'surface' => 'page',
                'started_at' => $base->copy()->addMinutes($a),
                'ended_at' => $base->copy()->addMinutes($b),
                'talking_seconds' => 0,
            ], $extra));
        }
    }

    private function getReport(array $query)
    {
        return $this->withToken('user-token')->getJson('/api/usage/report?' . http_build_query(array_merge([
            'stores' => ['1001'],
            'from' => '2026-10-12T00:00:00Z',
            'to' => '2026-10-13T00:00:00Z',
            'tz' => 'UTC',
        ], $query)));
    }

    public function test_report_merges_overlaps_and_splits_presence(): void
    {
        $this->seedSegments(42, 'Maria Manager', [
            // two tiles connected 08:00-10:00 at once: on screen 2h, not 4h
            ['session', null, 0, 120, ['end_reason' => 'duplicate_identity']],
            ['session', null, 0, 120, []],
            ['main', null, 0, 90, []],
            ['camera', 'visible', 0, 60, []],
            ['camera', 'no_face', 60, 75, []],
            ['camera', 'covered', 75, 90, []],
            ['mic', 'open', 0, 30, ['talking_seconds' => 600]],
            ['mic', 'dead', 30, 40, []],
        ]);
        $this->seedSegments(43, 'Omar Manager', [['session', null, 0, 30, []], ['camera', 'visible', 0, 30, []]]);

        $r = $this->getReport([])->assertOk()->json();

        $maria = collect($r['users'])->firstWhere('user_id', 42);
        $this->assertSame(7200, $maria['on_screen']);
        $this->assertSame(3600, $maria['present']);
        $this->assertSame(3600, $maria['away']);
        $this->assertSame(900, $maria['no_face']);
        $this->assertSame(900, $maria['covered']);
        $this->assertSame(1800, $maria['camera_off']);
        $this->assertSame(2400, $maria['mic_open']);
        $this->assertSame(600, $maria['talking']);
        $this->assertSame(600, $maria['dead_mic']);
        $this->assertSame(1200, $maria['silent']);
        $this->assertSame(1, $maria['kicks']);
        $this->assertSame('Make line', $maria['favorite']['name']);
        $this->assertSame(5400, $maria['favorite']['seconds']);

        $this->assertSame(2, $r['totals']['managers']);
        $this->assertSame(7200 + 1800, $r['totals']['on_screen']);
        $this->assertSame([['user_id' => 42, 'name' => 'Maria Manager'], ['user_id' => 43, 'name' => 'Omar Manager']], $r['managers']);
        // 2026-10-12 is a Monday: 08:00 UTC present = 60 min (Maria) + 30 min (Omar)
        $this->assertSame(3600 + 1800, $r['hours']['present'][0][8]);
        $this->assertSame('2026-10-12', $r['days'][0]['date']);
        $this->assertSame(2, $r['days'][0]['managers']);
        $this->assertSame(1, $r['totals']['screens_watched']);
    }

    public function test_report_counts_days_in_the_viewers_time_zone_and_filters_managers(): void
    {
        // 03:00-05:00 UTC is 23:00-01:00 in New York (UTC-4): it straddles two local days.
        $this->seedSegments(42, 'Maria Manager', [['session', null, 0, 120, []]], Carbon::parse('2026-10-12 03:00:00', 'UTC'));
        $this->seedSegments(43, 'Omar Manager', [['session', null, 0, 60, []]]);

        $r = $this->getReport([
            'from' => '2026-10-11T04:00:00Z', 'to' => '2026-10-13T04:00:00Z', 'tz' => 'America/New_York', 'users' => [42],
        ])->assertOk()->json();

        $this->assertSame([['2026-10-11', 3600], ['2026-10-12', 3600]], array_map(fn ($d) => [$d['date'], $d['on_screen']], $r['days']));
        $this->assertCount(1, $r['users']);
        $this->assertCount(2, $r['managers'], 'the filter list ignores the filter');
    }

    public function test_report_requires_stores_and_bounds_the_range(): void
    {
        $this->getReport(['stores' => []])->assertUnprocessable();
        $this->getReport(['stores' => ['nope']])->assertNotFound();
        $this->getReport(['from' => '2026-01-01T00:00:00Z', 'to' => '2026-10-01T00:00:00Z'])->assertStatus(422);
    }

    public function test_timeline_returns_one_managers_segments_with_names(): void
    {
        $this->seedSegments(42, 'Maria Manager', [['session', null, 0, 30, []], ['camera', 'covered', 0, 10, []]]);
        $this->seedSegments(43, 'Omar Manager', [['session', null, 0, 30, []]]);

        $this->withToken('user-token')->getJson('/api/usage/timeline?' . http_build_query([
            'stores' => ['1001'], 'user_id' => 42, 'from' => '2026-10-12T00:00:00Z', 'to' => '2026-10-13T00:00:00Z',
        ]))
            ->assertOk()
            ->assertJsonCount(2, 'segments')
            ->assertJsonPath('segments.0.station', 'Make line')
            ->assertJsonPath('segments.0.store', '1001');
    }
}
