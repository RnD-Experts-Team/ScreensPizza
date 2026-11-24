<?php

namespace Tests\Feature;

use App\Models\ScreenUsageSegment;
use App\Models\Station;
use App\Models\Store;
use App\Models\User;
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

        // Segments belong to replicated pizzasys users.
        User::factory()->create(['id' => 42, 'name' => 'Maria Manager']);
        User::factory()->create(['id' => 43, 'name' => 'Omar Manager']);

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
        // The make line held Maria's main tile 08:00-09:30: her connected, present and talking time then.
        $screen = collect($r['screens'])->firstWhere('station_id', $this->station->id);
        $this->assertSame([5400, 3600, 600], [$screen['connected'], $screen['present'], $screen['talking']]);
    }

    public function test_report_counts_work_days_and_filters_managers(): void
    {
        // 03:00-05:00 UTC is 23:00-01:00 in New York (UTC-4): one shift, so all of it is the 11th's.
        $this->seedSegments(42, 'Maria Manager', [['session', null, 0, 120, []]], Carbon::parse('2026-10-12 03:00:00', 'UTC'));
        $this->seedSegments(43, 'Omar Manager', [['session', null, 0, 60, []]]);

        $r = $this->getReport([
            'from' => '2026-10-11T04:00:00Z', 'to' => '2026-10-13T04:00:00Z', 'tz' => 'America/New_York', 'users' => [42],
        ])->assertOk()->json();

        $this->assertSame([['2026-10-11', 7200]], array_map(fn ($d) => [$d['date'], $d['on_screen']], $r['days']));
        $this->assertCount(1, $r['users']);
        $this->assertCount(2, $r['managers'], 'the filter list ignores the filter');
    }

    public function test_boots_on_the_ground_gives_each_manager_and_station_their_time(): void
    {
        $register = Station::create(['store_id' => 7, 'name' => 'Register', 'room_name' => 'register-1001', 'type' => Station::TYPE_STANDARD]);
        // Maria: the make line in her main tile 08:00-09:30, a face on camera 08:00-09:00, 10 min talking.
        $this->seedSegments(42, 'Maria Manager', [
            ['session', null, 0, 120, []],
            ['main', null, 0, 90, []],
            ['camera', 'visible', 0, 60, []],
            ['mic', 'open', 0, 30, ['talking_seconds' => 600]],
        ]);
        // Omar, her partner at the same store: connected 30 min, present all of it, no main tile.
        $this->seedSegments(43, 'Omar Manager', [['session', null, 0, 30, ['station_id' => null]], ['camera', 'visible', 0, 30, ['station_id' => null]]]);

        $r = $this->withToken('user-token')->getJson('/api/usage/boots-on-the-ground?' . http_build_query([
            'stores' => ['1001'], 'from' => '2026-10-12T00:00:00Z', 'to' => '2026-10-13T00:00:00Z', 'tz' => 'UTC',
        ]))->assertOk()->json();

        $this->assertSame(['connected' => 7200 + 1800, 'present' => 3600 + 1800, 'talking' => 600], $r['totals']);
        $this->assertSame(['Maria Manager', 'Omar Manager'], array_column($r['managers'], 'name'));
        [$maria, $omar] = $r['managers'];
        $this->assertSame(['1001'], $maria['stores']);
        $this->assertSame([7200, 3600, 600], [$maria['connected'], $maria['present'], $maria['talking']]);
        $this->assertSame([['station_id' => $this->station->id, 'connected' => 5400, 'present' => 3600, 'talking' => 600]], $maria['stations']);
        $this->assertSame([], $omar['stations']);
        // Every standard station is listed, the unwatched register too; only what the cards need.
        $this->assertSame(['Make line', 'Register'], array_column($r['stations'], 'name'));
        $this->assertSame($register->id, $r['stations'][1]['station_id']);
        $this->assertSame(['range', 'totals', 'managers', 'stations'], array_keys($r));

        $this->withToken('user-token')->getJson('/api/usage/boots-on-the-ground?stores[]=1001')->assertUnprocessable();
    }

    public function test_browsers_legacy_timezone_names_are_accepted(): void
    {
        // Chrome still reports e.g. Asia/Calcutta and Europe/Kiev, which PHP only lists as backward-compatible names.
        foreach (['Asia/Calcutta', 'Europe/Kiev', 'Asia/Saigon', 'US/Eastern'] as $tz) {
            $this->withToken('user-token')->getJson('/api/usage/boots-on-the-ground?' . http_build_query([
                'stores' => ['1001'], 'from' => '2026-10-12T00:00:00Z', 'to' => '2026-10-13T00:00:00Z', 'tz' => $tz,
            ]))->assertOk();
        }

        $this->withToken('user-token')->getJson('/api/usage/boots-on-the-ground?' . http_build_query([
            'stores' => ['1001'], 'from' => '2026-10-12T00:00:00Z', 'to' => '2026-10-13T00:00:00Z', 'tz' => 'Not/AZone',
        ]))->assertUnprocessable();
    }

    public function test_report_requires_stores_but_not_a_short_range(): void
    {
        $this->getReport(['stores' => []])->assertUnprocessable();
        $this->getReport(['stores' => ['nope']])->assertNotFound();
        $this->getReport(['from' => '2025-01-01T00:00:00Z', 'to' => '2026-10-01T00:00:00Z'])->assertOk();
    }

    /** @return list<array<string, string>> the CSV's rows keyed by header */
    private function export(array $query, string $path = '/api/usage/export'): array
    {
        config(['services.x_secret_key' => 'excel-secret']);
        $csv = $this->withHeader('X-Secret-Key', 'excel-secret')
            ->get($path . '?' . http_build_query($query))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->streamedContent();
        $lines = array_map(fn ($line) => str_getcsv($line, escape: ''), array_filter(explode("\n", $csv)));
        $header = array_shift($lines);

        return array_map(fn ($line) => array_combine($header, $line), $lines);
    }

    public function test_export_splits_by_day_store_and_manager_in_the_stores_clock(): void
    {
        Store::create(['id' => 8, 'store_number' => '1002']);
        // 03:00-05:00 UTC is 23:00-01:00 in New York: one hour on each local day.
        $this->seedSegments(42, 'Maria Manager', [
            ['session', null, 0, 120, []],
            ['camera', 'visible', 0, 60, []],
            ['mic', 'open', 0, 120, ['talking_seconds' => 1800]],
        ], Carbon::parse('2026-10-12 03:00:00', 'UTC'));
        $this->seedSegments(42, 'Maria Manager', [['session', null, 0, 30, ['store_id' => 8, 'end_reason' => 'duplicate_identity']]]);

        // Another zone asked for: its own midnight-to-midnight days (Puerto Rico is UTC-4, as New York is in October).
        $rows = $this->export(['start_date' => '2026-10-11', 'end_date' => '2026-10-12', 'tz' => 'America/Puerto_Rico']);

        $this->assertSame(
            [['2026-10-11', '1001'], ['2026-10-12', '1001'], ['2026-10-12', '1002']],
            array_map(fn ($r) => [$r['Date'], $r['Store']], $rows),
        );
        $this->assertSame(['1', '1', '0', '0.25'], [$rows[0]['Connected hours'], $rows[0]['Present hours'], $rows[0]['Away hours'], $rows[0]['Talking hours']]);
        $this->assertSame(['1', '0', '1', '0.25'], [$rows[1]['Connected hours'], $rows[1]['Present hours'], $rows[1]['Away hours'], $rows[1]['Talking hours']]);
        $this->assertSame(['Maria Manager', '42', '0.5', '1', '1'], [$rows[2]['Manager'], $rows[2]['Manager ID'], $rows[2]['Connected hours'], $rows[2]['Visits'], $rows[2]['Kicks']]);

        // The stores' clock: work days. 23:00-01:00 and 04:00-04:30 are one shift (a 3h break),
        // so all of it is the 11th's, at both stores.
        $rows = $this->export(['start_date' => '2026-10-11', 'end_date' => '2026-10-12']);

        $this->assertSame([['2026-10-11', '1001'], ['2026-10-11', '1002']], array_map(fn ($r) => [$r['Date'], $r['Store']], $rows));
        $this->assertSame(['2', '1', '1', '0.5'], [$rows[0]['Connected hours'], $rows[0]['Present hours'], $rows[0]['Away hours'], $rows[0]['Talking hours']]);
        $this->assertSame(['Maria Manager', '42', '0.5', '1', '1'], [$rows[1]['Manager'], $rows[1]['Manager ID'], $rows[1]['Connected hours'], $rows[1]['Visits'], $rows[1]['Kicks']]);

        $this->assertSame(['1002'], array_column($this->export(['start_date' => '2026-10-11', 'end_date' => '2026-10-12', 'stores' => '1002']), 'Store'));
    }

    public function test_station_export_cuts_each_managers_time_by_the_main_tile_and_adds_up(): void
    {
        $till = Station::create(['store_id' => 7, 'name' => 'Till', 'room_name' => 'till-1001', 'type' => Station::TYPE_STANDARD]);
        // 08:00-09:10 connected; the make line holds the main tile until 08:45, the till until 09:00, then nothing.
        $this->seedSegments(42, 'Maria Manager', [
            ['session', null, 0, 70, ['end_reason' => 'duplicate_identity']],
            ['main', null, 0, 45, []],
            ['camera', 'visible', 0, 30, []],
            ['mic', 'open', 0, 60, ['talking_seconds' => 600]],
            ['talk_all', null, 0, 10, ['station_id' => null]],
        ]);
        $this->seedSegments(42, 'Maria Manager', [
            ['session', null, 0, 60, ['station_id' => $till->id]],
            ['main', null, 45, 60, ['station_id' => $till->id]],
            ['camera', 'visible', 50, 55, ['station_id' => $till->id]],
        ]);
        $query = ['start_date' => '2026-10-12', 'end_date' => '2026-10-12', 'tz' => 'UTC'];

        $rows = $this->export($query, '/api/usage/export/stations');

        $this->assertSame(
            [
                ['Make line', (string) $this->station->id, '0.75', '0.5', '0.125', '0.1667', '1', '0'],
                ['No main tile', '', '0.1667', '0', '0', '0', '0', '1'],
                ['Till', (string) $till->id, '0.25', '0.0833', '0.0417', '0', '0', '0'],
            ],
            array_map(fn ($r) => [$r['Station'], $r['Station ID'], $r['Connected hours'], $r['Present hours'], $r['Talking hours'],
                $r['Talk to all hours'], $r['Visits'], $r['Kicks']], $rows),
        );

        // Column by column, the stations add up to the manager's row.
        [$manager] = $this->export($query);
        foreach ($manager as $column => $value) {
            if (! in_array($column, ['Date', 'Store', 'Manager ID', 'Manager'], true)) {
                $this->assertEqualsWithDelta((float) $value, array_sum(array_column($rows, $column)), 0.001, $column);
            }
        }
    }

    public function test_boots_on_the_ground_export_gives_fiscal_ranges_and_the_weeks_detail(): void
    {
        $landing = Station::create(['store_id' => 7, 'name' => 'Landing', 'room_name' => 'landing-1001', 'type' => Station::TYPE_STANDARD]);
        // This week (Tue 10-06 .. Mon 10-12): Landing in the main tile 08:00-09:00, then nothing until 10:00.
        $this->seedSegments(42, 'Maria Manager', [
            ['session', null, 0, 120, []],
            ['main', null, 0, 60, ['station_id' => $landing->id]],
            ['camera', 'visible', 0, 120, []],
            ['mic', 'open', 0, 60, ['talking_seconds' => 1800]],
        ]);
        // Last week's Monday, the same weekday as `date`: one present hour.
        $this->seedSegments(42, 'Maria Manager', [
            ['session', null, 0, 60, []],
            ['camera', 'visible', 0, 60, []],
        ], Carbon::parse('2026-10-05 08:00:00', 'UTC'));
        config(['services.x_secret_key' => 'excel-secret']);

        $res = $this->withHeader('X-Secret-Key', 'excel-secret')
            ->get('/api/usage/export/boots-on-the-ground?date=2026-10-12&tz=UTC')
            ->assertOk();

        $res->assertJsonPath('filtering.fiscal_year', 2026)
            ->assertJsonPath('filtering.week_number', 41)
            ->assertJsonPath('filtering.week_start', '2026-10-06')
            ->assertJsonPath('filtering.period_number', 11)
            ->assertJsonPath('filtering.quarter_start', '2026-09-08')
            ->assertJsonPath('filtering.year_start', '2025-12-30');
        $store = $res->json('by_store.1001');
        $this->assertEquals(['start' => '2026-10-06', 'end' => '2026-10-12', 'video_hours' => 2, 'voice_hours' => 0.5], $store['week']['current']);
        $this->assertEquals(['start' => '2026-09-29', 'end' => '2026-10-05', 'video_hours' => 1, 'voice_hours' => 0], $store['week']['previous']);
        $this->assertEquals(0, $store['week']['same_week_last_year']['video_hours']);
        $this->assertEquals('2025-10-07', $store['week']['same_week_last_year']['start']);
        $this->assertEquals(3, $store['quarter']['current']['video_hours']);
        $this->assertEquals(3, $store['year']['current']['video_hours']);
        $this->assertArrayNotHasKey('same_year_last_year', $store['year']);

        $detail = $store['week_detail'];
        $this->assertEquals(['video_hours' => 1, 'voice_hours' => 0.5], $detail['columns']['landing']);
        $this->assertEquals(['video_hours' => 1, 'voice_hours' => 0], $detail['columns']['others']);
        $this->assertEquals(['video_hours' => 0, 'voice_hours' => 0], $detail['columns']['making']);
        $this->assertEquals(['Landing', 'No main tile'], array_keys($detail['stations']));
        $this->assertCount(7, $detail['days']);
        $this->assertEquals(['date' => '2026-10-12', 'video_hours' => 2, 'voice_hours' => 0.5], $detail['days'][6]);

        $this->withHeader('X-Secret-Key', 'wrong')->get('/api/usage/export/boots-on-the-ground?date=2026-10-12')->assertUnauthorized();
        $this->withHeader('X-Secret-Key', 'excel-secret')->get('/api/usage/export/boots-on-the-ground?date=12-10-2026')->assertUnprocessable();
    }

    /**
     * Three managers around the night of Mon 12 Oct 2026 (New York, UTC-4):
     *  - Maria: 22:00-01:00 on the 11th; 20:00-02:00 on the 12th on the make line, 10 min talking;
     *    09:00-10:00 on the 13th, 7h after the night ended, so a new shift.
     *  - Omar: 23:00-23:30 on the make line, then 02:00-03:00 with no main tile: a 2.5h break, one shift.
     *  - Zed: connected 08:00 on the 12th to 04:00 on the 13th: 16h on the 12th, the last 4h a new shift.
     */
    private function seedWorkDays(): void
    {
        User::factory()->create(['id' => 44, 'name' => 'Zed Long']);
        $utc = fn (string $at) => Carbon::parse($at, 'UTC');
        $this->seedSegments(42, 'Maria Manager', [['session', null, 0, 180, []], ['camera', 'visible', 0, 180, []], ['main', null, 0, 180, []]], $utc('2026-10-12 02:00'));
        $this->seedSegments(42, 'Maria Manager', [
            ['session', null, 0, 360, []], ['camera', 'visible', 0, 360, []], ['main', null, 0, 360, []],
            ['mic', 'open', 0, 60, ['talking_seconds' => 600]],
        ], $utc('2026-10-13 00:00'));
        $this->seedSegments(42, 'Maria Manager', [['session', null, 0, 60, []], ['camera', 'visible', 0, 60, []], ['main', null, 0, 60, []]], $utc('2026-10-13 13:00'));
        $this->seedSegments(43, 'Omar Manager', [['session', null, 0, 30, []], ['camera', 'visible', 0, 30, []], ['main', null, 0, 30, []]], $utc('2026-10-13 03:00'));
        $this->seedSegments(43, 'Omar Manager', [['session', null, 0, 60, []], ['camera', 'visible', 0, 60, []]], $utc('2026-10-13 06:00'));
        $this->seedSegments(44, 'Zed Long', [['session', null, 0, 1200, []], ['camera', 'visible', 0, 1200, []]], $utc('2026-10-12 12:00'));
    }

    private function daily(string $date): array
    {
        config(['services.x_secret_key' => 'excel-secret']);

        return $this->withHeader('X-Secret-Key', 'excel-secret')->getJson('/api/usage/export/daily?date='.$date)->assertOk()->json();
    }

    /** [name, present, talking, [[station, present, talking], ...]] per manager, hours. */
    private function summary(array $daily): array
    {
        return array_map(fn (array $m) => [$m['name'], $m['present_hours'], $m['talking_hours'],
            array_map(fn (array $s) => [$s['station'], $s['present_hours'], $s['talking_hours']], $m['stations'])], $daily['managers']);
    }

    public function test_daily_counts_each_shift_whole_on_the_day_it_started(): void
    {
        $this->seedWorkDays();
        $this->travelTo(Carbon::parse('2026-10-14 18:00', 'America/New_York'));

        $day = $this->daily('2026-10-12');
        $this->assertSame(['date' => '2026-10-12', 'tz' => 'America/New_York', 'final' => true], array_intersect_key($day, array_flip(['date', 'tz', 'final'])));
        $this->assertEquals([
            ['Maria Manager', 6, 0.17, [['Make line', 6, 0.17]]],
            ['Omar Manager', 1.5, 0, [['Make line', 0.5, 0], ['No main tile', 1, 0]]],
            ['Zed Long', 16, 0, [['No main tile', 16, 0]]],
        ], $this->summary($day));
        $this->assertSame([42, User::find(42)->email, '1001', $this->station->id], [
            $day['managers'][0]['user_id'], $day['managers'][0]['email'], $day['managers'][0]['stations'][0]['store'], $day['managers'][0]['stations'][0]['station_id'],
        ]);

        $this->assertEquals([['Maria Manager', 3, 0, [['Make line', 3, 0]]]], $this->summary($this->daily('2026-10-11')));
        $this->assertEquals([
            ['Maria Manager', 1, 0, [['Make line', 1, 0]]],
            ['Zed Long', 4, 0, [['No main tile', 4, 0]]],
        ], $this->summary($this->daily('2026-10-13')));
    }

    public function test_daily_is_not_final_while_a_shift_of_the_day_may_still_run(): void
    {
        $this->seedWorkDays();

        // A shift can start up to midnight and last 16h, so the 12th is final from 16:00 on the 13th.
        $this->travelTo(Carbon::parse('2026-10-13 15:59', 'America/New_York'));
        $this->assertFalse($this->daily('2026-10-12')['final']);
        $this->travelTo(Carbon::parse('2026-10-13 16:00', 'America/New_York'));
        $this->assertTrue($this->daily('2026-10-12')['final']);
    }

    public function test_the_aggregate_gives_the_same_numbers_and_replaces_the_segments_for_stored_days(): void
    {
        $this->seedWorkDays();
        $this->travelTo(Carbon::parse('2026-10-14 18:00', 'America/New_York'));
        $query = ['start_date' => '2026-10-11', 'end_date' => '2026-10-13'];
        $range = ['stores' => ['1001'], 'from' => '2026-10-11T04:00:00Z', 'to' => '2026-10-14T04:00:00Z', 'tz' => 'America/New_York'];
        $monitoring = fn () => [
            $this->getReport($range)->assertOk()->json(),
            $this->getReport($range + ['users' => [43]])->assertOk()->json(),
            $this->withToken('user-token')->getJson('/api/usage/boots-on-the-ground?'.http_build_query($range))->assertOk()->json(),
        ];
        $live = [$this->daily('2026-10-12'), $this->export($query), $this->export($query, '/api/usage/export/stations'), ...$monitoring()];

        // Never past the latest final day (the 13th), whatever is asked.
        $this->artisan('usage:aggregate-daily', ['--start' => '2026-10-11', '--end' => '2026-10-20'])
            ->expectsOutputToContain('not final yet')
            ->assertSuccessful();
        $this->assertSame(['2026-10-11', '2026-10-12', '2026-10-13'], \DB::table('screen_usage_aggregated_days')->orderBy('work_date')->pluck('work_date')->map(fn ($d) => substr($d, 0, 10))->all());

        // Stored days are read from the aggregate alone: the exports, the monitoring report (whole
        // and for one manager) and the dashboards' Boots on the Ground give the same answers.
        ScreenUsageSegment::query()->delete();
        $this->assertEquals($live, [$this->daily('2026-10-12'), $this->export($query), $this->export($query, '/api/usage/export/stations'), ...$monitoring()]);
        $this->assertSame(['Maria Manager', 'Omar Manager', 'Zed Long'], array_column($live[3]['managers'], 'name'));
        $this->assertSame([43], array_column($live[4]['users'], 'user_id'));

        // Rebuilding a day replaces it.
        $this->artisan('usage:aggregate-daily', ['--start' => '2026-10-12', '--end' => '2026-10-12'])->assertSuccessful();
        $this->assertSame([], $this->daily('2026-10-12')['managers']);
        $this->assertCount(2, $this->daily('2026-10-13')['managers']);
    }

    public function test_a_stored_managers_day_at_stores_outside_the_filter_is_read_from_the_segments(): void
    {
        // Maria at both stores at once, 08:00-10:00 at 1001 and 09:00-11:00 at 1002.
        Store::create(['id' => 8, 'store_number' => '1002']);
        $this->seedSegments(42, 'Maria Manager', [['session', null, 0, 120, []], ['camera', 'visible', 0, 120, []]]);
        $this->seedSegments(42, 'Maria Manager', [['session', null, 60, 180, ['store_id' => 8]], ['camera', 'visible', 60, 180, ['store_id' => 8]]]);
        $this->travelTo(Carbon::parse('2026-10-14 18:00', 'America/New_York'));
        $range = fn (array $stores) => ['stores' => $stores, 'from' => '2026-10-12T04:00:00Z', 'to' => '2026-10-13T04:00:00Z', 'tz' => 'America/New_York'];

        $live = [$this->getReport($range(['1001']))->json(), $this->getReport($range(['1001', '1002']))->json()];
        $this->artisan('usage:aggregate-daily', ['--start' => '2026-10-12'])->assertSuccessful();
        $stored = [$this->getReport($range(['1001']))->json(), $this->getReport($range(['1001', '1002']))->json()];

        $this->assertEquals($live, $stored);
        // Merged across both stores: 3h, not 2h + 2h; at 1001 alone, 2h.
        $this->assertSame([7200, 10800], [$stored[0]['users'][0]['on_screen'], $stored[1]['users'][0]['on_screen']]);
    }

    public function test_daily_needs_the_secret_and_a_date(): void
    {
        config(['services.x_secret_key' => 'excel-secret']);
        $this->withHeader('X-Secret-Key', 'wrong')->get('/api/usage/export/daily?date=2026-10-12')->assertUnauthorized();
        $this->withHeader('X-Secret-Key', 'excel-secret')->get('/api/usage/export/daily')->assertUnprocessable();
        $this->withHeader('X-Secret-Key', 'excel-secret')->get('/api/usage/export/daily?date=12-10-2026')->assertUnprocessable();
        $this->withHeader('X-Secret-Key', 'excel-secret')->getJson('/api/usage/export/daily?date=2026-10-12&stores=nope')->assertNotFound();
    }

    public function test_export_needs_the_secret_and_valid_dates_but_no_range_cap(): void
    {
        config(['services.x_secret_key' => 'excel-secret']);
        $this->withHeader('X-Secret-Key', 'wrong')->get('/api/usage/export?start_date=2026-10-11&end_date=2026-10-12')->assertUnauthorized();
        $this->withHeader('X-Secret-Key', 'excel-secret')->get('/api/usage/export?start_date=2025-01-01&end_date=2026-10-31')->assertOk();
        $this->withHeader('X-Secret-Key', 'excel-secret')->get('/api/usage/export?start_date=2026-10-12&end_date=2026-10-11')->assertUnprocessable();
        $this->withHeader('X-Secret-Key', 'excel-secret')->getJson('/api/usage/export?start_date=2026-10-11&end_date=2026-10-12&stores=nope')->assertNotFound();
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

    public function test_mine_returns_only_the_signed_in_persons_own_segments_in_range(): void
    {
        $this->seedSegments(42, 'Maria Manager', [['session', null, 0, 30, []], ['mic', 'open', 0, 30, ['talking_seconds' => 120]]]);
        $this->seedSegments(43, 'Omar Manager', [['session', null, 0, 30, []]]);
        $this->seedSegments(42, 'Maria Manager', [['session', null, 0, 30, []]], Carbon::parse('2026-10-10 08:00:00', 'UTC'));

        $this->withToken('user-token')->getJson('/api/usage/mine?' . http_build_query([
            'from' => '2026-10-12T00:00:00Z', 'to' => '2026-10-13T00:00:00Z', 'user_id' => 43,
        ]))
            ->assertOk()
            ->assertJsonCount(2, 'segments')
            ->assertJsonPath('segments.1.talking_seconds', 120)
            ->assertJsonStructure(['now', 'segments' => [['id', 'kind', 'state', 'surface', 'started_at', 'ended_at', 'talking_seconds']]]);
    }
}
