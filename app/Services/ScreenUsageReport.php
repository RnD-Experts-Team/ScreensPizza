<?php
namespace App\Services;

use App\Models\ScreenUsageSegment;
use App\Models\Station;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Carbon;

/**
 * Screen Project monitoring report: how managers used Screen Project across
 * the given stores over [from, to), aggregated here so the dashboard gets
 * numbers, not tens of thousands of raw segments.
 *
 * Every duration is in SECONDS. Days and hours are counted in the viewer's
 * time zone ($tz). Overlapping segments of one manager (the same camera seen
 * by several tiles, a session per connected station) are merged before they
 * are summed, so nothing is counted twice.
 *
 * Definitions:
 *  - on screen  = connected to at least one station (page or mini-player)
 *  - present    = a face in front of the camera (camera state `visible`)
 *  - away       = on screen but not present
 *  - favorite   = the station in the big main tile for the longest
 */
class ScreenUsageReport
{
    /** Only these kinds of seconds per manager are kept; see build(). */
    private const LISTS = ['session', 'pip', 'visible', 'no_face', 'covered', 'camera', 'mic', 'dead', 'talk_all'];

    /**
     * @param  array<int, string>  $stores  store id => store number
     * @param  array<int>|null  $userIds  only these managers (null = all)
     */
    public function build(array $stores, Carbon $from, Carbon $to, ?array $userIds, string $tz): array
    {
        $fromTs = $from->getTimestamp();
        $toTs = $to->getTimestamp();
        $zone = new DateTimeZone($tz);

        $stations = Station::whereIn('store_id', array_keys($stores))
            ->where('type', Station::TYPE_STANDARD)
            ->orderBy('store_id')->orderBy('name')
            ->get(['id', 'store_id', 'name'])
            ->keyBy('id');

        $users = [];
        $query = ScreenUsageSegment::query()->toBase()
            ->whereIn('store_id', array_keys($stores))
            ->where('started_at', '<', $to)
            ->where('ended_at', '>', $from)
            ->when($userIds, fn ($q) => $q->whereIn('user_id', $userIds))
            ->orderBy('started_at')
            ->select(['user_id', 'user_name', 'store_id', 'station_id', 'kind', 'state', 'surface', 'started_at', 'ended_at', 'talking_seconds', 'end_reason']);

        foreach ($query->cursor() as $s) {
            $start = Carbon::parse($s->started_at, 'UTC')->getTimestamp();
            $end = Carbon::parse($s->ended_at, 'UTC')->getTimestamp();
            $a = max($start, $fromTs);
            $b = min($end, $toTs);
            if ($b <= $a) {
                continue;
            }
            $iv = [$a, $b];
            $uid = (int) $s->user_id;
            $u = &$users[$uid];
            if ($u === null) {
                $u = ['name' => null, 'stores' => [], 'last_seen' => 0, 'kicks' => 0, 'talking' => 0.0, 'talk_all_talking' => 0.0,
                    'lists' => array_fill_keys(self::LISTS, []), 'by_store' => [], 'main' => [], 'station_mic' => [], 'station_cam' => [], 'mic_spans' => []];
            }
            $u['name'] = $s->user_name ?: $u['name'];
            $storeId = (int) $s->store_id;
            $u['stores'][$storeId] = true;
            $u['last_seen'] = max($u['last_seen'], $b);
            $station = $s->station_id !== null ? (int) $s->station_id : null;
            // Talking seconds, pro-rated when only part of the segment is inside the range.
            $talk = $end > $start ? $s->talking_seconds * (($b - $a) / ($end - $start)) : 0;

            switch ($s->kind) {
                case 'session':
                    $u['lists']['session'][] = $iv;
                    $u['by_store'][$storeId]['session'][] = $iv;
                    if ($s->surface === 'pip') {
                        $u['lists']['pip'][] = $iv;
                    }
                    if ($s->end_reason === 'duplicate_identity' && $end <= $toTs) {
                        $u['kicks']++;
                        $u['by_store'][$storeId]['kicks'] = ($u['by_store'][$storeId]['kicks'] ?? 0) + 1;
                    }
                    break;
                case 'main':
                    if ($station !== null) {
                        $u['main'][$station][] = $iv;
                    }
                    break;
                case 'camera':
                    $u['lists']['camera'][] = $iv;
                    if (in_array($s->state, ['visible', 'no_face', 'covered'], true)) {
                        $u['lists'][$s->state][] = $iv;
                    }
                    if ($s->state === 'visible') {
                        $u['by_store'][$storeId]['visible'][] = $iv;
                    }
                    if ($station !== null) {
                        $u['station_cam'][$station][] = $iv;
                    }
                    break;
                case 'mic':
                    $u['lists']['mic'][] = $iv;
                    if ($s->state === 'dead') {
                        $u['lists']['dead'][] = $iv;
                    }
                    $u['talking'] += $talk;
                    $u['mic_spans'][] = [$a, $b, $talk];
                    $u['by_store'][$storeId]['talking'] = ($u['by_store'][$storeId]['talking'] ?? 0) + $talk;
                    if ($station !== null) {
                        $u['station_mic'][$station][] = $iv;
                    }
                    break;
                case 'talk_all':
                    $u['lists']['talk_all'][] = $iv;
                    $u['talk_all_talking'] += $talk;
                    break;
            }
            unset($u);
        }

        $days = [];
        $hours = ['present' => array_fill(0, 7, array_fill(0, 24, 0)), 'away' => array_fill(0, 7, array_fill(0, 24, 0))];
        $camera = ['present' => 0, 'no_face' => 0, 'covered' => 0, 'off' => 0];
        $mic = ['talking' => 0, 'silent' => 0, 'dead' => 0, 'off' => 0];
        $storeRows = [];
        $stationRows = [];
        $userRows = [];

        foreach ($users as $uid => $u) {
            $sets = $this->derive($u['lists']);
            $onScreen = $sets['on_screen'];
            $present = $sets['present'];
            $away = $sets['away'];

            $t = fn (array $ivs) => $this->total($ivs);
            $micOpen = $t($sets['mic_open']);
            $talking = (int) round(min($micOpen, $u['talking']));
            $dead = $t($sets['dead_mic']);

            // Favorite screen: the longest in the main tile.
            $mainTotals = [];
            foreach ($u['main'] as $station => $ivs) {
                $mainTotals[$station] = $t($this->merge($ivs));
            }
            arsort($mainTotals);
            $favorite = array_key_first($mainTotals);

            $row = [
                'user_id' => $uid,
                'name' => $u['name'] ?: "#{$uid}",
                'stores' => array_values(array_map(fn ($id) => $stores[$id] ?? (string) $id, array_keys($u['stores']))),
                'on_screen' => $t($onScreen),
                'present' => $t($present),
                'away' => $t($away),
                'no_face' => $t($sets['no_face']),
                'covered' => $t($sets['covered']),
                'camera_off' => $t($sets['camera_off']),
                'mic_open' => $micOpen,
                'talking' => $talking,
                'silent' => max(0, $micOpen - $talking - $dead),
                'dead_mic' => $dead,
                'talk_all' => $t($sets['talk_all']),
                'talk_all_talking' => (int) round($u['talk_all_talking']),
                'pip' => $t($sets['pip']),
                'visits' => count($onScreen),
                'kicks' => $u['kicks'],
                'last_seen' => Carbon::createFromTimestamp($u['last_seen'])->toIso8601String(),
                'favorite' => $favorite === null ? null : [
                    'station_id' => $favorite,
                    'name' => $stations[$favorite]->name ?? "#{$favorite}",
                    'store' => isset($stations[$favorite]) ? ($stores[$stations[$favorite]->store_id] ?? null) : null,
                    'seconds' => $mainTotals[$favorite],
                ],
            ];
            $userRows[] = $row;

            $camera['present'] += $row['present'];
            $camera['no_face'] += $row['no_face'];
            $camera['covered'] += $row['covered'];
            $camera['off'] += $row['camera_off'];
            $mic['talking'] += $row['talking'];
            $mic['dead'] += $row['dead_mic'];
            $mic['silent'] += $row['silent'];
            $mic['off'] += $t($sets['mic_off']);

            // Per day / per weekday-hour, in the viewer's clock.
            $this->bucket($onScreen, $zone, function (string $day, int $wd, int $h, int $sec) use (&$days, $uid) {
                $days[$day]['on_screen'] = ($days[$day]['on_screen'] ?? 0) + $sec;
                $days[$day]['managers'][$uid] = true;
            });
            $this->bucket($present, $zone, function (string $day, int $wd, int $h, int $sec) use (&$days, &$hours) {
                $days[$day]['present'] = ($days[$day]['present'] ?? 0) + $sec;
                $hours['present'][$wd][$h] += $sec;
            });
            $this->bucket($away, $zone, function (string $day, int $wd, int $h, int $sec) use (&$hours) {
                $hours['away'][$wd][$h] += $sec;
            });
            foreach ($u['mic_spans'] as [$a, $b, $talk]) {
                $len = $b - $a;
                $this->bucket([[$a, $b]], $zone, function (string $day, int $wd, int $h, int $sec) use (&$days, $talk, $len) {
                    $days[$day]['talking'] = ($days[$day]['talking'] ?? 0) + $talk * ($sec / $len);
                });
            }

            foreach ($u['by_store'] as $storeId => $s) {
                $r = &$storeRows[$storeId];
                $r ??= ['store' => $stores[$storeId] ?? (string) $storeId, 'managers' => 0, 'on_screen' => 0, 'present' => 0, 'talking' => 0, 'kicks' => 0];
                $r['managers']++;
                $r['on_screen'] += $t($this->merge($s['session'] ?? []));
                $r['present'] += $t($this->merge($s['visible'] ?? []));
                $r['talking'] += (int) round($s['talking'] ?? 0);
                $r['kicks'] += $s['kicks'] ?? 0;
                unset($r);
            }

            foreach ([['main', 'main'], ['station_mic', 'mic'], ['station_cam', 'camera']] as [$src, $key]) {
                foreach ($u[$src] as $station => $ivs) {
                    $stationRows[$station][$key] = ($stationRows[$station][$key] ?? 0) + $t($this->merge($ivs));
                    if ($key === 'main') {
                        $stationRows[$station]['managers'][$uid] = true;
                    }
                }
            }
        }

        usort($userRows, fn ($a, $b) => $b['on_screen'] <=> $a['on_screen']);
        ksort($days);

        $screens = [];
        foreach ($stations as $id => $station) {
            $r = $stationRows[$id] ?? [];
            $screens[] = [
                'station_id' => $id,
                'name' => $station->name,
                'store' => $stores[$station->store_id] ?? null,
                'main' => $r['main'] ?? 0,
                'mic' => $r['mic'] ?? 0,
                'camera' => $r['camera'] ?? 0,
                'managers' => count($r['managers'] ?? []),
            ];
        }
        usort($screens, fn ($a, $b) => $b['main'] <=> $a['main']);

        $sum = fn (string $k) => array_sum(array_column($userRows, $k));
        $onScreenTotal = $sum('on_screen');

        return [
            'range' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String(), 'tz' => $tz],
            'totals' => [
                'managers' => count($userRows),
                'on_screen' => $onScreenTotal,
                'present' => $sum('present'),
                'away' => $sum('away'),
                'no_face' => $sum('no_face'),
                'covered' => $sum('covered'),
                'mic_open' => $sum('mic_open'),
                'talking' => $sum('talking'),
                'dead_mic' => $sum('dead_mic'),
                'talk_all' => $sum('talk_all'),
                'pip' => $sum('pip'),
                'visits' => $sum('visits'),
                'kicks' => $sum('kicks'),
                'screens' => count($screens),
                'screens_watched' => count(array_filter($screens, fn ($s) => $s['main'] > 0)),
            ],
            'users' => $userRows,
            'stores' => array_values($storeRows),
            'screens' => $screens,
            'days' => array_map(fn ($day, $d) => [
                'date' => $day,
                'on_screen' => $d['on_screen'] ?? 0,
                'present' => $d['present'] ?? 0,
                'talking' => (int) round($d['talking'] ?? 0),
                'managers' => count($d['managers'] ?? []),
            ], array_keys($days), $days),
            'hours' => $hours,
            'camera' => $camera,
            'mic' => $mic,
        ];
    }

    /**
     * The same numbers as build()'s managers, split by local day and store: one row per
     * day × store × manager, every duration in seconds. A manager's overlapping segments
     * are merged within each store; `visits` counts the on-screen stretches that start on
     * the day, `kicks` the duplicate-login cuts that happen on it.
     *
     * With $byStation each of those rows is cut further by the station in the manager's
     * main tile at each moment: the station's row holds the time it was in the main tile
     * (connected, present, talking... during it), and time with no station in the main tile
     * goes to a row with no station. So, column by column, a manager's station rows add up
     * to their row without $byStation. When two tiles claim the main tile at once, the one
     * that took it last wins.
     *
     * @param  array<int, string>  $stores  store id => store number
     * @param  array<int>|null  $userIds  only these managers (null = all)
     * @return list<array<string, int|float|string|null>>
     */
    public function dailyRows(array $stores, Carbon $from, Carbon $to, string $tz, bool $byStation = false, ?array $userIds = null): array
    {
        $fromTs = $from->getTimestamp();
        $toTs = $to->getTimestamp();
        $zone = new DateTimeZone($tz);
        $day = fn (int $ts) => (new DateTimeImmutable('@'.$ts))->setTimezone($zone)->format('Y-m-d');

        $groups = [];
        $query = ScreenUsageSegment::query()->toBase()
            ->whereIn('store_id', array_keys($stores))
            ->where('started_at', '<', $to)
            ->where('ended_at', '>', $from)
            ->when($userIds, fn ($q) => $q->whereIn('user_id', $userIds))
            ->orderBy('started_at')
            ->select(['user_id', 'user_name', 'store_id', 'station_id', 'kind', 'state', 'surface', 'started_at', 'ended_at', 'talking_seconds', 'end_reason']);

        foreach ($query->cursor() as $s) {
            $start = Carbon::parse($s->started_at, 'UTC')->getTimestamp();
            $end = Carbon::parse($s->ended_at, 'UTC')->getTimestamp();
            $a = max($start, $fromTs);
            $b = min($end, $toTs);
            if ($b <= $a) {
                continue;
            }
            $iv = [$a, $b];
            $g = &$groups[$s->user_id.'|'.$s->store_id];
            $g ??= ['user_id' => (int) $s->user_id, 'store_id' => (int) $s->store_id, 'name' => null,
                'lists' => array_fill_keys(self::LISTS, []), 'tiles' => [], 'mic_spans' => [], 'kicks' => []];
            $g['name'] = $s->user_name ?: $g['name'];

            switch ($s->kind) {
                case 'session':
                    $g['lists']['session'][] = $iv;
                    if ($s->surface === 'pip') {
                        $g['lists']['pip'][] = $iv;
                    }
                    if ($s->end_reason === 'duplicate_identity' && $end <= $toTs) {
                        $g['kicks'][] = $end;
                    }
                    break;
                case 'main':
                    if ($byStation && $s->station_id !== null) {
                        $g['tiles'][] = [$a, $b, (int) $s->station_id];
                    }
                    break;
                case 'camera':
                    $g['lists']['camera'][] = $iv;
                    if (in_array($s->state, ['visible', 'no_face', 'covered'], true)) {
                        $g['lists'][$s->state][] = $iv;
                    }
                    break;
                case 'mic':
                    $g['lists']['mic'][] = $iv;
                    if ($s->state === 'dead') {
                        $g['lists']['dead'][] = $iv;
                    }
                    // Talking seconds, pro-rated when only part of the segment is inside the range.
                    $g['mic_spans'][] = [$a, $b, $end > $start ? $s->talking_seconds * (($b - $a) / ($end - $start)) : 0];
                    break;
                case 'talk_all':
                    $g['lists']['talk_all'][] = $iv;
                    break;
            }
            unset($g);
        }

        $stationNames = $byStation
            ? Station::whereIn('id', array_unique(array_merge([], ...array_map(fn ($g) => array_column($g['tiles'], 2), array_values($groups)))))->pluck('name', 'id')->all()
            : [];

        $rows = [];
        foreach ($groups as $g) {
            $sets = $this->derive($g['lists']);
            unset($sets['mic_off']);

            // The parts this manager's time is cut into: one per main-tile station, plus the
            // rest (key ''). Without $byStation the rest is everything.
            $tiles = $this->mainTiles($g['tiles']);
            $tiled = $this->merge(array_merge([], ...array_values($tiles)));
            $cut = fn (string $part, array $ivs) => $part === ''
                ? $this->subtract($ivs, $tiled)
                : $this->subtract($ivs, $this->subtract($ivs, $tiles[$part]));
            $partAt = function (int $ts) use ($tiles): string {
                foreach ($tiles as $station => $ivs) {
                    foreach ($ivs as [$a, $b]) {
                        if ($ts >= $a && $ts < $b) {
                            return (string) $station;
                        }
                    }
                }

                return '';
            };

            $days = [];
            $rawTalk = [];
            foreach (array_merge([''], array_map('strval', array_keys($tiles))) as $part) {
                foreach ($sets as $metric => $ivs) {
                    $this->bucket($cut($part, $ivs), $zone, function (string $d, int $wd, int $h, int $sec) use (&$days, $part, $metric) {
                        $days[$d][$part][$metric] = ($days[$d][$part][$metric] ?? 0) + $sec;
                    });
                }
                foreach ($g['mic_spans'] as [$a, $b, $talk]) {
                    $len = $b - $a;
                    $this->bucket($cut($part, [[$a, $b]]), $zone, function (string $d, int $wd, int $h, int $sec) use (&$rawTalk, $part, $talk, $len) {
                        $rawTalk[$d][$part] = ($rawTalk[$d][$part] ?? 0) + $talk * ($sec / $len);
                    });
                }
            }
            foreach ($sets['on_screen'] as [$a]) {
                $days[$day($a)][$partAt($a)]['visits'] = ($days[$day($a)][$partAt($a)]['visits'] ?? 0) + 1;
            }
            foreach ($g['kicks'] as $ts) {
                $days[$day($ts)][$partAt($ts - 1)]['kicks'] = ($days[$day($ts)][$partAt($ts - 1)]['kicks'] ?? 0) + 1;
            }

            foreach ($days as $d => $parts) {
                // The day's talking, capped at its mic time as in build(), then shared out
                // by where it was said, so the parts add up to the manager's day.
                $micOpenDay = array_sum(array_map(fn ($m) => $m['mic_open'] ?? 0, $parts));
                $rawDay = array_sum($rawTalk[$d] ?? []);
                $talkDay = (int) round(min($micOpenDay, $rawDay));

                foreach ($parts as $part => $m) {
                    $micOpen = $m['mic_open'] ?? 0;
                    $dead = $m['dead_mic'] ?? 0;
                    $talking = $rawDay > 0 ? $talkDay * (($rawTalk[$d][$part] ?? 0) / $rawDay) : 0;
                    $station = $part === '' ? null : (int) $part;
                    $row = [
                        'date' => $d,
                        'store' => $stores[$g['store_id']] ?? (string) $g['store_id'],
                        'station_id' => $station,
                        'station' => $station === null ? ($byStation ? 'No main tile' : null) : ($stationNames[$station] ?? "#{$station}"),
                        'user_id' => $g['user_id'],
                        'name' => $g['name'] ?: "#{$g['user_id']}",
                        'on_screen' => $m['on_screen'] ?? 0,
                        'present' => $m['present'] ?? 0,
                        'away' => $m['away'] ?? 0,
                        'no_face' => $m['no_face'] ?? 0,
                        'covered' => $m['covered'] ?? 0,
                        'camera_off' => $m['camera_off'] ?? 0,
                        'mic_open' => $micOpen,
                        'talking' => $talking,
                        'silent' => max(0, $micOpen - $talking - $dead),
                        'dead_mic' => $dead,
                        'talk_all' => $m['talk_all'] ?? 0,
                        'pip' => $m['pip'] ?? 0,
                        'visits' => $m['visits'] ?? 0,
                        'kicks' => $m['kicks'] ?? 0,
                    ];
                    if (array_filter(array_slice($row, 6)) !== []) {
                        $rows[] = $row;
                    }
                }
            }
        }

        usort($rows, fn ($x, $y) => [$x['date'], $x['store'], $x['station'], $x['name']] <=> [$y['date'], $y['store'], $y['station'], $y['name']]);

        return $rows;
    }

    /**
     * One manager's main-tile segments ([from, to, station]) as non-overlapping time per
     * station: where two overlap, the one that started later holds the main tile.
     *
     * @param  list<array{0:int,1:int,2:int}>  $tiles
     * @return array<int, list<array{0:int,1:int}>>  station id => merged intervals
     */
    private function mainTiles(array $tiles): array
    {
        usort($tiles, fn ($x, $y) => $x[0] <=> $y[0]);
        $points = array_values(array_unique(array_merge(array_column($tiles, 0), array_column($tiles, 1))));
        sort($points);

        $out = [];
        $active = [];
        $next = 0;
        for ($k = 0; $k < count($points) - 1; $k++) {
            [$p, $q] = [$points[$k], $points[$k + 1]];
            while ($next < count($tiles) && $tiles[$next][0] <= $p) {
                $active[] = $tiles[$next++];
            }
            $active = array_values(array_filter($active, fn ($t) => $t[1] > $p));
            if ($active !== []) {
                $out[$active[count($active) - 1][2]][] = [$p, $q];
            }
        }

        return array_map([$this, 'merge'], $out);
    }

    /**
     * The merged interval sets every number is read from, out of one manager's raw lists.
     *
     * @param  array<string, list<array{0:int,1:int}>>  $lists
     * @return array<string, list<array{0:int,1:int}>>
     */
    private function derive(array $lists): array
    {
        $l = array_map([$this, 'merge'], $lists);
        $present = $l['visible'];
        $covered = $this->subtract($l['covered'], $present);

        return [
            'on_screen' => $l['session'],
            'present' => $present,
            'away' => $this->subtract($l['session'], $present),
            'covered' => $covered,
            'no_face' => $this->subtract($this->subtract($l['no_face'], $present), $covered),
            'camera_off' => $this->subtract($l['session'], $l['camera']),
            'mic_open' => $l['mic'],
            'mic_off' => $this->subtract($l['session'], $l['mic']),
            'dead_mic' => $l['dead'],
            'talk_all' => $l['talk_all'],
            'pip' => $l['pip'],
        ];
    }

    /** @param array<int, array{0:int,1:int}> $ivs */
    private function merge(array $ivs): array
    {
        usort($ivs, fn ($x, $y) => $x[0] <=> $y[0]);
        $out = [];
        foreach ($ivs as [$a, $b]) {
            $last = count($out) - 1;
            if ($last >= 0 && $a <= $out[$last][1]) {
                $out[$last][1] = max($out[$last][1], $b);
            } else {
                $out[] = [$a, $b];
            }
        }

        return $out;
    }

    private function total(array $merged): int
    {
        $sum = 0;
        foreach ($merged as [$a, $b]) {
            $sum += $b - $a;
        }

        return $sum;
    }

    /** a − b, both merged. */
    private function subtract(array $a, array $b): array
    {
        $out = [];
        foreach ($a as [$start, $end]) {
            $cursor = $start;
            foreach ($b as [$bs, $be]) {
                if ($be <= $cursor || $bs >= $end) {
                    continue;
                }
                if ($bs > $cursor) {
                    $out[] = [$cursor, $bs];
                }
                $cursor = max($cursor, $be);
                if ($cursor >= $end) {
                    break;
                }
            }
            if ($cursor < $end) {
                $out[] = [$cursor, $end];
            }
        }

        return $out;
    }

    /**
     * Splits intervals at the viewer's local hour boundaries and calls
     * $add(day 'Y-m-d', weekday 0=Mon..6=Sun, hour 0..23, seconds) per piece.
     */
    private function bucket(array $ivs, DateTimeZone $zone, callable $add): void
    {
        foreach ($ivs as [$a, $b]) {
            $cursor = $a;
            while ($cursor < $b) {
                $local = (new DateTimeImmutable('@'.$cursor))->setTimezone($zone);
                $hour = (int) $local->format('G');
                $next = $local->setTime($hour, 0)->modify('+1 hour')->getTimestamp();
                if ($next <= $cursor) {
                    $next = $cursor + 3600; // DST edge: never stall
                }
                $end = min($b, $next);
                $add($local->format('Y-m-d'), (int) $local->format('N') - 1, $hour, $end - $cursor);
                $cursor = $end;
            }
        }
    }
}
