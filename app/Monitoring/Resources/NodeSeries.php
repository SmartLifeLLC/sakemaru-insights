<?php

namespace App\Monitoring\Resources;

use App\Models\Monitoring\Node;
use App\Models\Monitoring\ServerMetric;
use Carbon\CarbonImmutable;

/**
 * ノードの時系列（5 分集約 ins_monitor_server_metrics）を表示用に間引いて返す。DB 側で時間/日に集約してから読む（最大 400 点）。
 * ノード一覧（最大 2 台を並べて表示）とノード詳細で共用。
 */
class NodeSeries
{
    /** 表示範囲 → [秒数, 集約幅（秒）, ラベル書式] */
    public const RANGES = [
        '1h' => [3600, 300, 'H:i'],
        '3h' => [3 * 3600, 300, 'H:i'],
        '12h' => [12 * 3600, 300, 'H:i'],
        '1d' => [86400, 300, 'H:i'],
        '3d' => [3 * 86400, 900, 'm/d H時'],
        '1w' => [7 * 86400, 3600, 'm/d H時'],
        '1y' => [365 * 86400, 86400, 'm/d'],
    ];

    public const LABELS = ['1h' => '1 時間', '3h' => '3 時間', '12h' => '12 時間', '1d' => '1 日', '3d' => '3 日', '1w' => '1 週間'];

    /** @return array{labels:string[],cpu:array,cpu_max:array,mem:array,disk:array,load:array,iowait:array,swap:array,source:string} */
    public static function agg(Node $n, string $range, CarbonImmutable $now, int $cores): array
    {
        [$span, $step, $labelFmt] = self::RANGES[$range] ?? self::RANGES['1d'];
        $from = $now->subSeconds($span);
        $q = ServerMetric::query()->where('node_id', $n->id)->where('bucket_start', '>=', $from);
        $driver = $q->getConnection()->getDriverName();
        $bucketExpr = $driver === 'sqlite'
            ? "(CAST(strftime('%s', bucket_start) AS INTEGER) / {$step}) * {$step}"
            : "FLOOR(UNIX_TIMESTAMP(bucket_start) / {$step}) * {$step}";
        $rows = $q->selectRaw("{$bucketExpr} AS b, COUNT(*) AS n, AVG(cpu_avg) AS cpu_avg, MAX(cpu_max) AS cpu_max, AVG(iowait_avg) AS iowait_avg, AVG(load1_avg) AS load1_avg, AVG(mem_used_pct_avg) AS mem_used_pct_avg, MAX(mem_used_pct_max) AS mem_used_pct_max, MAX(disk_used_pct_max) AS disk_used_pct_max, MAX(swap_used_pct_max) AS swap_used_pct_max")
            ->groupByRaw($bucketExpr)->orderByRaw('b')->limit(400)->get();
        $out = ['labels' => [], 'cpu' => [], 'cpu_max' => [], 'mem' => [], 'disk' => [], 'load' => [], 'iowait' => [], 'swap' => [], 'source' => $step >= 3600 ? ($step >= 86400 ? '日別（5 分集約から）' : '時間別（5 分集約から）') : ($step === 900 ? '15 分別（5 分集約から）' : '5 分集約（DB）')];
        foreach ($rows as $r) {
            $out['labels'][] = CarbonImmutable::createFromTimestamp((int) $r->b, 'Asia/Tokyo')->format($labelFmt);
            $out['cpu'][] = round((float) $r->cpu_avg, 1);
            $out['cpu_max'][] = round((float) $r->cpu_max, 1);
            $out['mem'][] = round((float) $r->mem_used_pct_avg, 1);
            $out['disk'][] = round((float) $r->disk_used_pct_max, 1);
            $out['load'][] = round((float) $r->load1_avg / max(1, $cores) * 100, 1);
            $out['iowait'][] = round((float) $r->iowait_avg, 1);
            $out['swap'][] = round((float) $r->swap_used_pct_max, 1);
        }

        return $out;
    }
}
