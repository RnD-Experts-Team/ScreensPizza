<?php

namespace Tests\Feature\Reports;

use App\Http\Requests\ReportDatasetQueryRequest;
use App\Reports\ReportDatasets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * The contract routes end to end, through the real auth middleware with
 * pizzasys faked. Two stores: 03795-00044 (id 1) and 03795-00042 (id 2).
 * Timestamps are stored in UTC; the tests run on SQLite, so they sit at
 * midday UTC where the UTC and New York dates agree.
 */
class ReportDatasetQueryTest extends TestCase
{
    use FakesAuthServer;
    use RefreshDatabase;

    private const USAGE = '/api/report-datasets/screens.usage/query';

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeAuthServer();
        $this->seedUsage();
    }

    private function seedUsage(): void
    {
        $now = now();
        // Plain inserts: the Store model adds a Drive Through station of its own.
        DB::table('stores')->insert([
            ['id' => 1, 'store_number' => '03795-00044', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'store_number' => '03795-00042', 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('stations')->insert([
            ['id' => 1, 'store_id' => 1, 'name' => 'Make line', 'room_name' => 'make-1', 'type' => 'standard', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'store_id' => 1, 'name' => 'Drive Through', 'room_name' => 'dt-1', 'type' => 'drive_through', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 3, 'store_id' => 2, 'name' => 'Make line', 'room_name' => 'make-2', 'type' => 'standard', 'created_at' => $now, 'updated_at' => $now],
        ]);

        // store, station, user, name, kind, state, start, end
        foreach ([
            [1, 1, 42, 'Maria Manager', 'camera', 'visible', '2026-10-21 14:00:00', '2026-10-21 14:10:00'],
            [1, 1, 42, 'Maria Manager', 'camera', 'no_face', '2026-10-21 14:10:00', '2026-10-21 14:15:00'],
            [1, 2, 42, 'Maria Manager', 'session', null, '2026-10-22 15:00:00', '2026-10-22 16:00:00'],
            [2, 3, 77, 'Omar Ortiz', 'camera', 'visible', '2026-10-21 14:00:00', '2026-10-21 14:20:00'],
            // Outside the period asked for below.
            [1, 1, 42, 'Maria Manager', 'camera', 'visible', '2026-10-30 14:00:00', '2026-10-30 14:30:00'],
        ] as [$store, $station, $user, $name, $kind, $state, $start, $end]) {
            DB::table('screen_usage_segments')->insert([
                'id' => (string) Str::ulid(), 'store_id' => $store, 'station_id' => $station,
                'user_id' => $user, 'user_name' => $name, 'kind' => $kind, 'state' => $state,
                'surface' => 'page', 'started_at' => $start, 'ended_at' => $end,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function body(array $overrides = []): array
    {
        return array_replace([
            'stores' => ['03795-00044'],
            'date_from' => '2026-10-21',
            'date_to' => '2026-10-27',
            'limit' => 50,
        ], $overrides);
    }

    public function test_usage_is_grouped_for_the_requested_store_only(): void
    {
        $this->postJson(self::USAGE, $this->body([
            'columns' => ['store', 'date', 'kind'],
            'group_by' => ['store', 'date', 'kind'],
            'aggregates' => [
                ['column' => 'seconds', 'fn' => 'sum', 'alias' => 'seconds'],
                ['column' => 'segments', 'fn' => 'count', 'alias' => 'segments'],
            ],
            'filters' => [['column' => 'kind', 'op' => 'in', 'value' => ['camera', 'session']]],
        ]), $this->headers())
            ->assertOk()
            ->assertJson([
                'columns' => [
                    ['key' => 'store', 'type' => 'string'], ['key' => 'date', 'type' => 'date'], ['key' => 'kind', 'type' => 'enum'],
                    ['key' => 'seconds', 'type' => 'integer'], ['key' => 'segments', 'type' => 'integer'],
                ],
                'rows' => [
                    ['03795-00044', '2026-10-21', 'camera', 900, 2],
                    ['03795-00044', '2026-10-22', 'session', 3600, 1],
                ],
                'next_cursor' => null,
                'truncated' => false,
                'meta' => ['row_count' => 2, 'contract_version' => 1],
            ]);

        // pizzasys judged this exact route, with the store codes of the body.
        Http::assertSent(fn (ClientRequest $request) => str_starts_with($request->url(), 'http://auth.test')
            && $request['path'] === self::USAGE
            && $request['service'] === 'Screens'
            && $request['store_context']['body']['stores'] === ['03795-00044']
            && $request['store_context']['path']['dataset'] === 'screens.usage');
    }

    public function test_the_station_and_manager_are_groupable(): void
    {
        $this->postJson(self::USAGE, $this->body([
            'columns' => ['station', 'station_type', 'manager'],
            'aggregates' => [['column' => 'seconds', 'fn' => 'sum', 'alias' => 'seconds']],
        ]), $this->headers())
            ->assertOk()
            ->assertJsonPath('rows', [
                ['Drive Through', 'drive_through', 'Maria Manager', 3600],
                ['Make line', 'standard', 'Maria Manager', 900],
            ]);
    }

    public function test_empty_stores_apply_no_store_filter(): void
    {
        $this->postJson(self::USAGE, $this->body([
            'stores' => [],
            'columns' => [],
            'group_by' => ['store'],
            'aggregates' => [['column' => 'segments', 'fn' => 'count', 'alias' => 'segments']],
        ]), $this->headers())
            ->assertOk()
            ->assertJsonPath('rows', [['03795-00042', 1], ['03795-00044', 3]]);
    }

    public function test_a_store_pizzasys_refuses_is_a_403(): void
    {
        $this->tokenAuthorized = false;

        $this->postJson(self::USAGE, $this->body(['stores' => ['03795-00042'], 'columns' => ['store']]), $this->headers())
            ->assertForbidden();
    }

    public function test_unknown_columns_are_a_422(): void
    {
        $this->postJson(self::USAGE, $this->body(['columns' => ['store', 'user_id']]), $this->headers())
            ->assertUnprocessable()
            ->assertJsonPath('errors.columns', ['Unknown column user_id.']);

        $this->postJson(self::USAGE, $this->body(['aggregates' => [['column' => 'minutes', 'fn' => 'sum']]]), $this->headers())
            ->assertUnprocessable();

        $this->postJson(self::USAGE, $this->body(['date_to' => '2026-10-01', 'columns' => ['store']]), $this->headers())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_to');
    }

    public function test_the_period_is_not_capped(): void
    {
        $this->postJson(self::USAGE, $this->body(['date_from' => '2020-01-01', 'date_to' => '2026-10-27', 'columns' => ['store']]), $this->headers())
            ->assertOk();
    }

    public function test_a_caller_cannot_set_the_statement_timeout(): void
    {
        $this->assertArrayNotHasKey('timeout_ms', (new ReportDatasetQueryRequest())->rules());
    }

    public function test_the_catalog_names_screens_and_lists_the_dataset(): void
    {
        $response = $this->getJson('/api/report-datasets', $this->headers())
            ->assertOk()
            ->assertJsonPath('service', 'Screens')
            ->assertJsonPath('contract_version', 1);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $response->json('contract_sha1'));
        $this->assertSame(['screens.usage'], array_column($response->json('datasets'), 'key'));
        $this->assertSame(['store', 'date'], $response->json('datasets.0.grain'));
        $this->assertSame('/report-datasets/screens.usage/query', $response->json('datasets.0.path'));
        $this->assertTrue($response->json('datasets.0.heavy'));
        $this->assertTrue($response->json('datasets.0.dated'));

        // No user id and no SQL behind a column.
        $keys = array_column($response->json('datasets.0.columns'), 'key');
        $this->assertSame(['store', 'date', 'station', 'station_type', 'kind', 'state', 'manager', 'seconds', 'segments'], $keys);
        $this->assertStringNotContainsString('screen_usage_segments.', $response->getContent());
    }

    public function test_every_dataset_has_its_own_literal_query_route(): void
    {
        foreach (ReportDatasets::DATASETS as $dataset) {
            $route = app('router')->getRoutes()->match(Request::create('/api/report-datasets/'.$dataset::KEY.'/query', 'POST'));
            $this->assertSame('api/report-datasets/'.$dataset::KEY.'/query', $route->uri());
            $this->assertContains('auth.token.store', $route->gatherMiddleware());
        }
    }

    public function test_stream_mode_sends_ndjson_columns_rows_and_the_done_line(): void
    {
        $query = [
            'columns' => ['store', 'date', 'kind'],
            'group_by' => ['store', 'date', 'kind'],
            'aggregates' => [['column' => 'seconds', 'fn' => 'sum', 'alias' => 'seconds']],
        ];
        $page = $this->postJson(self::USAGE, $this->body($query), $this->headers())->assertOk()->json();

        $response = $this->postJson(self::USAGE, $this->body(['stream' => true, 'limit' => null] + $query), $this->headers())->assertOk();

        $this->assertStringStartsWith('application/x-ndjson', $response->headers->get('Content-Type'));
        $lines = array_map(fn (string $line) => json_decode($line, true), explode("\n", rtrim($response->streamedContent(), "\n")));
        $rows = array_slice($lines, 1, -1);

        $this->assertSame(['columns' => $page['columns']], $lines[0]);
        $this->assertNotEmpty($rows);
        $this->assertEqualsCanonicalizing($page['rows'], $rows);
        $this->assertSame(['done' => true, 'row_count' => count($rows)], end($lines));
    }

    public function test_a_stream_query_with_a_limit_is_a_422(): void
    {
        $this->postJson(self::USAGE, $this->body(['stream' => true, 'limit' => 10]), $this->headers())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('limit');
    }
}
