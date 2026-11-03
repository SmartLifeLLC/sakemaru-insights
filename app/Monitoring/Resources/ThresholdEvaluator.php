<?php

namespace App\Monitoring\Resources;

/**
 * サーバー資源のしきい値判定（v2 第5章 / V-066）。純粋関数。監視ルール画面の「判定テスト」でも使う。
 *
 * latest は収集器が送る直近値（ResourceSampler::latestPayload）:
 *   ts, cores, current{...}, disks[{mount,used_pct,inode_used_pct,free_gb}], recent{t:[], cpu:[], iowait:[], load1:[], mem_available_pct:[], swap_used_pct:[]}
 * 継続時間の判定: 直近 duration 秒の窓に含まれる 30 秒値が「全て」超過し、かつ窓の始点までデータがある場合だけ超過とする。
 */
final class ThresholdEvaluator
{
    public const LABELS = [
        'cpu_pct' => 'CPU 使用率',
        'iowait_pct' => 'I/O 待ち',
        'load_per_core' => '負荷（load/コア）',
        'mem_available_pct' => 'メモリ空き',
        'swap_used_pct' => 'スワップ使用率',
        'disk_used_pct' => 'ディスク使用率',
        'inode_used_pct' => 'inode 使用率',
        'process_missing' => 'プロセス',
    ];

    private const SERIES = ['cpu_pct' => 'cpu', 'iowait_pct' => 'iowait', 'load_per_core' => 'load1', 'mem_available_pct' => 'mem_available_pct', 'swap_used_pct' => 'swap_used_pct'];

    private const LEVELS = ['critical', 'error', 'warning'];

    /** 既定値 → ノード別上書き（metric 単位の部分上書き、disabled=true で判定しない） */
    public static function resolve(?array $nodeThresholds): array
    {
        $out = (array) config('monitoring.resources.thresholds', []);
        foreach ((array) $nodeThresholds as $metric => $over) {
            if (isset($out[$metric]) && is_array($over)) {
                $out[$metric] = array_merge($out[$metric], array_filter($over, fn ($v) => $v !== null && $v !== ''));
            }
        }

        return $out;
    }

    /**
     * @return array<string,array{metric:string,mount:?string,level:string,value:?float,threshold:?float,duration:int,label:string}>
     *                                                                                                                               key = "cpu_pct" / "disk_used_pct:/var"
     */
    public static function evaluate(array $latest, array $thresholds, ?int $nowTs = null): array
    {
        $nowTs ??= (int) ($latest['ts'] ?? time());
        $cores = max(1, (int) ($latest['cores'] ?? 1));
        $out = [];
        foreach (self::SERIES as $metric => $seriesKey) {
            $th = $thresholds[$metric] ?? null;
            if (! $th || ! empty($th['disabled'])) {
                continue;
            }
            $below = ($th['direction'] ?? 'above') === 'below';
            $duration = (int) ($th['duration'] ?? 0);
            $times = array_map('intval', (array) ($latest['recent']['t'] ?? []));
            $vals = (array) ($latest['recent'][$seriesKey] ?? []);
            if ($metric === 'load_per_core') {
                $vals = array_map(fn ($v) => $v === null ? null : $v / $cores, $vals);
            }
            $current = $metric === 'load_per_core'
                ? (isset($latest['current']['load1']) ? $latest['current']['load1'] / $cores : null)
                : ($latest['current'][$seriesKey] ?? null);
            $level = 'ok';
            $hit = null;
            foreach (self::LEVELS as $lv) {
                if (! isset($th[$lv])) {
                    continue;
                }
                $limit = (float) $th[$lv];
                $breach = fn ($v) => $v !== null && is_finite((float) $v) && ($below ? (float) $v < $limit : (float) $v >= $limit);
                $ok = $duration <= 0 ? $breach($current) : self::sustained($times, $vals, $nowTs, $duration, $breach);
                if ($ok) {
                    $level = $lv;
                    $hit = $limit;
                    break;
                }
            }
            $out[$metric] = ['metric' => $metric, 'mount' => null, 'level' => $level, 'value' => $current === null ? null : round((float) $current, 2), 'threshold' => $hit, 'duration' => $duration, 'label' => self::LABELS[$metric]];
        }
        foreach (['disk_used_pct' => 'used_pct', 'inode_used_pct' => 'inode_used_pct'] as $metric => $field) {
            $th = $thresholds[$metric] ?? null;
            if (! $th || ! empty($th['disabled'])) {
                continue;
            }
            foreach ((array) ($latest['disks'] ?? []) as $d) {
                $v = $d[$field] ?? null;
                if ($v === null) {
                    continue;
                }
                $level = 'ok';
                $hit = null;
                foreach (self::LEVELS as $lv) {
                    if (isset($th[$lv]) && (float) $v >= (float) $th[$lv]) {
                        $level = $lv;
                        $hit = (float) $th[$lv];
                        break;
                    }
                }
                $mount = (string) ($d['mount'] ?? '?');
                $out[$metric.':'.$mount] = ['metric' => $metric, 'mount' => $mount, 'level' => $level, 'value' => round((float) $v, 2), 'threshold' => $hit, 'duration' => 0, 'label' => self::LABELS[$metric].'（'.$mount.'）'];
            }
        }

        // 必須プロセスの停止（0 件の状態が error 秒＝既定 5 分以上続いた）。再起動の一時的な停止は数えない
        $pth = $thresholds['process_missing'] ?? null;
        if ($pth && empty($pth['disabled'])) {
            $limit = (int) ($pth['error'] ?? 300);
            foreach ((array) ($latest['processes'] ?? []) as $key => $p) {
                if (empty($p['required'])) {
                    continue;
                }
                $since = $p['missing_since'] ?? null;
                $missing = (int) ($p['count'] ?? 0) === 0 && $since !== null ? max(0, $nowTs - (int) $since) : 0;
                $label = (string) config('monitoring.resources.processes.'.$key.'.label', $key);
                $out['process_missing:'.$key] = ['metric' => 'process_missing', 'mount' => $key, 'level' => $missing >= $limit && (int) ($p['count'] ?? 0) === 0 ? 'error' : 'ok',
                    'value' => (int) ($p['count'] ?? 0), 'threshold' => $missing >= $limit ? (float) $limit : null, 'duration' => $limit, 'label' => $label.' の稼働', 'missing_seconds' => $missing];
            }
        }

        return $out;
    }

    /** 窓 [now-duration, now] の全点が超過し、窓の始点（1 サンプル分の余裕）までデータがある */
    private static function sustained(array $times, array $vals, int $nowTs, int $duration, callable $breach): bool
    {
        $from = $nowTs - $duration;
        $slack = (int) config('monitoring.resources.sample_seconds', 30);
        $inWindow = 0;
        $earliest = null;
        foreach ($times as $i => $t) {
            if ($t < $from - $slack || $t > $nowTs + $slack) {
                continue;
            }
            if ($t < $from) {
                // 窓の直前の点は「始点までデータがある」証拠としてだけ使う
                $earliest = $earliest === null ? $t : min($earliest, $t);

                continue;
            }
            $inWindow++;
            $earliest = $earliest === null ? $t : min($earliest, $t);
            if (! $breach($vals[$i] ?? null)) {
                return false;
            }
        }
        if ($inWindow === 0 || $earliest === null) {
            return false;
        }

        return $earliest <= $from + $slack;
    }
}
