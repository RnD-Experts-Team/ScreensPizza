<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Models\ScreenUsageSegment;
use App\Models\Station;
use App\Reports\Contract\Column;
use App\Reports\Contract\ReportDataset;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * How managers used Screen Project: one row per recorded segment, by the store,
 * the station it ran on and the local date it started. `seconds` adds up the
 * segments' raw lengths and `segments` counts them.
 *
 * Unlike /usage/report this does NOT merge overlapping segments of one manager
 * (the same camera seen by several tiles), so seconds can exceed wall-clock time:
 * read it per kind and state, not as a total. A segment belongs to the day it
 * started. The manager is the display name the browser reported; user ids,
 * surfaces and end reasons are not offered.
 *
 * Timestamps are stored in UTC (config/app.php); the day is the New York one.
 * CONVERT_TZ needs MySQL's time zone tables loaded, or every date is NULL.
 */
class UsageDataset extends ReportDataset
{
    public const KEY = 'screens.usage';

    private const REPORT_TIMEZONE = 'America/New_York';

    private const KIND_LABELS = [
        'session' => 'Connected',
        'main' => 'Main tile',
        'camera' => 'Camera',
        'mic' => 'Microphone',
        'talk_all' => 'Talk to all',
    ];

    private const STATE_LABELS = [
        'visible' => 'Face visible',
        'no_face' => 'No face',
        'covered' => 'Camera covered',
        'open' => 'Open',
        'dead' => 'Dead',
    ];

    private const STATION_TYPE_LABELS = [
        Station::TYPE_STANDARD => 'Standard',
        Station::TYPE_DRIVE_THROUGH => 'Drive through',
    ];

    public function label(): string
    {
        return 'Screen Project usage';
    }

    public function grain(): array
    {
        return ['store', 'date'];
    }

    public function columns(): array
    {
        return [
            new Column('store', 'Store', 'string', 'stores.store_number', groupable: true),
            new Column('date', 'Date', 'date', $this->dateColumn(), groupable: true),
            new Column('station', 'Station', 'string', 'stations.name', groupable: true),
            new Column('station_type', 'Station type', 'enum', 'stations.type', options: $this->options(self::STATION_TYPE_LABELS), groupable: true),
            new Column('kind', 'Kind', 'enum', 'screen_usage_segments.kind', options: $this->options(self::KIND_LABELS, ScreenUsageSegment::KINDS), groupable: true),
            new Column('state', 'State', 'enum', 'screen_usage_segments.state', options: $this->options(self::STATE_LABELS, ScreenUsageSegment::STATES), groupable: true),
            new Column('manager', 'Manager', 'string', 'screen_usage_segments.user_name', groupable: true),
            new Column('seconds', 'Seconds', 'integer', $this->seconds(), filterable: false, measure: true, aggregations: ['sum']),
            new Column('segments', 'Segments', 'integer', 'screen_usage_segments.id', filterable: false, measure: true, aggregations: ['count']),
        ];
    }

    public function baseQuery(): Builder
    {
        return DB::table('screen_usage_segments')
            ->join('stores', 'stores.id', '=', 'screen_usage_segments.store_id')
            ->leftJoin('stations', 'stations.id', '=', 'screen_usage_segments.station_id');
    }

    public function storeColumn(): string
    {
        return 'screen_usage_segments.store_id';
    }

    /** The local date, so it compares with the period's Y-m-d bounds. */
    public function dateColumn(): string
    {
        return $this->mysql()
            ? "DATE(CONVERT_TZ(screen_usage_segments.started_at, 'UTC', '".self::REPORT_TIMEZONE."'))"
            : 'DATE(screen_usage_segments.started_at)';
    }

    /** A grouped scan over the busiest table of this service. */
    public function heavy(): bool
    {
        return true;
    }

    private function seconds(): string
    {
        return $this->mysql()
            ? 'TIMESTAMPDIFF(SECOND, screen_usage_segments.started_at, screen_usage_segments.ended_at)'
            : "(strftime('%s', screen_usage_segments.ended_at) - strftime('%s', screen_usage_segments.started_at))";
    }

    /** The tests run on SQLite, which has neither CONVERT_TZ nor TIMESTAMPDIFF. */
    private function mysql(): bool
    {
        return DB::connection()->getDriverName() !== 'sqlite';
    }

    /**
     * @param  array<string, string>  $labels
     * @param  list<string>|null  $order  the values, in the order the model lists them
     * @return list<array{value: string, label: string}>
     */
    private function options(array $labels, ?array $order = null): array
    {
        return array_map(
            fn (string $value) => ['value' => $value, 'label' => $labels[$value]],
            $order ?? array_keys($labels),
        );
    }
}
