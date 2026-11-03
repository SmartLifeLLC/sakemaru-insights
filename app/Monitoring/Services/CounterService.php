<?php

namespace App\Monitoring\Services;

use App\Models\Monitoring\Counter;
use App\Monitoring\Enums\Outcome;
use App\Monitoring\Enums\Severity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * サイト別・時間枠別の小さな集約（仕様書 9.2 / 11.1）。
 * 単一行を全イベントから同期更新せず、取込単位でまとめて反映する（flush）。
 */
class CounterService
{
    public const KEY_SEVERITY_HOURLY = 'severity_hourly';

    public const KEY_PROBE_HOURLY = 'probe_hourly';         // n / fail / sum_ms / max_ms

    public const KEY_NOTIFICATION_HOURLY = 'notification_hourly'; // queued / delivered / failed / suppressed（channel 別は "slack_delivered" 等）

    /** @var array<string,array{site_id:int,key:string,bucket:CarbonImmutable,counts:array<string,int|float>,max:array<string,int|float>}> */
    private array $pending = [];

    public function bump(int $siteId, Severity $severity, ?Outcome $outcome, CarbonImmutable $at): void
    {
        $sums = [$severity->value => 1];
        if ($outcome) {
            $sums['outcome_'.$outcome->value] = 1;
        }
        $this->add($siteId, self::KEY_SEVERITY_HOURLY, $at, $sums);
    }

    /**
     * 汎用の時間別カウンタ。sums は加算、maxes は最大値を保持する。取込単位で flush する。
     *
     * @param  array<string,int|float>  $sums
     * @param  array<string,int|float>  $maxes
     */
    public function add(int $siteId, string $key, CarbonImmutable $at, array $sums, array $maxes = []): void
    {
        $bucket = $at->utc()->startOfHour();
        $k = $siteId.'|'.$key.'|'.$bucket->getTimestamp();
        $this->pending[$k] ??= ['site_id' => $siteId, 'key' => $key, 'bucket' => $bucket, 'counts' => [], 'max' => []];
        foreach ($sums as $name => $v) {
            $this->pending[$k]['counts'][$name] = ($this->pending[$k]['counts'][$name] ?? 0) + $v;
        }
        foreach ($maxes as $name => $v) {
            $this->pending[$k]['max'][$name] = max($this->pending[$k]['max'][$name] ?? $v, $v);
        }
    }

    public function flush(): void
    {
        if (! $this->pending) {
            return;
        }
        $pending = $this->pending;
        $this->pending = [];
        $now = CarbonImmutable::now('UTC');
        foreach ($pending as $p) {
            DB::connection('monitor_store')->transaction(function () use ($p, $now) {
                $find = fn () => Counter::query()->where('site_id', $p['site_id'])->where('counter_key', $p['key'])->where('bucket_start', $p['bucket'])->first();
                $row = $find();
                if (! $row) {
                    try {
                        Counter::query()->create(['site_id' => $p['site_id'], 'counter_key' => $p['key'], 'bucket_start' => $p['bucket'], 'counts_json' => $p['counts'] + $p['max'], 'calculated_at' => $now]);

                        return;
                    } catch (\Illuminate\Database\QueryException) {
                        $row = $find();
                    }
                }
                $counts = $row->counts_json ?? [];
                foreach ($p['counts'] as $k => $v) {
                    $counts[$k] = ($counts[$k] ?? 0) + $v;
                }
                foreach ($p['max'] as $k => $v) {
                    $counts[$k] = max($counts[$k] ?? $v, $v);
                }
                $row->counts_json = $counts;
                $row->calculated_at = $now;
                $row->save();
            }, 2);
        }
    }

    /**
     * 直近 N 時間の重大度別件数（サイト別）。
     *
     * @return array<int,array{counts:array<string,int>,calculated_at:?CarbonImmutable}>
     */
    public function recentBySite(int $hours = 24): array
    {
        $since = CarbonImmutable::now('UTC')->subHours($hours)->startOfHour();
        $rows = Counter::query()->where('counter_key', self::KEY_SEVERITY_HOURLY)->where('bucket_start', '>=', $since)->get();
        $out = [];
        foreach ($rows as $r) {
            $sid = (int) $r->site_id;
            $out[$sid] ??= ['counts' => [], 'calculated_at' => null];
            foreach ((array) $r->counts_json as $k => $v) {
                $out[$sid]['counts'][$k] = ($out[$sid]['counts'][$k] ?? 0) + (int) $v;
            }
            if (! $out[$sid]['calculated_at'] || $r->calculated_at->greaterThan($out[$sid]['calculated_at'])) {
                $out[$sid]['calculated_at'] = CarbonImmutable::instance($r->calculated_at);
            }
        }

        return $out;
    }

    /** @return array<int,array<string,int>> hour bucket (ts) => counts for sparkline */
    public function hourlySeries(int $hours = 24, ?int $siteId = null): array
    {
        $since = CarbonImmutable::now('UTC')->subHours($hours - 1)->startOfHour();
        $q = Counter::query()->where('counter_key', self::KEY_SEVERITY_HOURLY)->where('bucket_start', '>=', $since);
        if ($siteId) {
            $q->where('site_id', $siteId);
        }
        $series = [];
        for ($i = 0; $i < $hours; $i++) {
            $series[$since->addHours($i)->getTimestamp()] = ['INFO' => 0, 'WARNING' => 0, 'ERROR' => 0, 'CRITICAL' => 0];
        }
        foreach ($q->get() as $r) {
            $ts = CarbonImmutable::instance($r->bucket_start)->getTimestamp();
            if (! isset($series[$ts])) {
                continue;
            }
            foreach (['INFO', 'WARNING', 'ERROR', 'CRITICAL'] as $k) {
                $series[$ts][$k] += (int) ($r->counts_json[$k] ?? 0);
            }
        }

        return $series;
    }
}
