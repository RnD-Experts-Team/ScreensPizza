<?php

namespace Tests\Feature;

use App\Models\ScreenUsageSegment;
use App\Models\Station;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_lists_segments_overlapping_the_range(): void
    {
        $this->report([
            $this->segment(),
            $this->segment([
                'started_at' => now()->subDays(3)->toIso8601String(),
                'ended_at' => now()->subDays(3)->addMinutes(5)->toIso8601String(),
            ]),
        ])->assertNoContent();

        $this->withToken('user-token')
            ->getJson('/api/1001/usage/segments?' . http_build_query([
                'from' => now()->subHour()->toIso8601String(),
                'to' => now()->toIso8601String(),
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'segments')
            ->assertJsonPath('segments.0.user_name', 'Maria Manager')
            ->assertJsonFragment(['room_name' => 'make-line-1001']);
    }
}
