<?php

namespace App\Monitoring\Resources;

/**
 * 資源サンプリングの状態機械（v2 第5章）。時刻とスナップショットを外から与えるので、時計を進めて検証できる（V-061〜064）。
 *
 * - 1 秒ごとに軽量スナップショット（/proc/stat・meminfo・loadavg・pressure・diskstats・net/dev）を取り、直近 pre_seconds 分をメモリに保持。
 * - 警告域に入ったら保持分を含めて 1 秒値をバーストファイルへ書き、警告域を出てから post_seconds まで続ける。平常時は書かない。
 * - 30 秒境界でディスク使用率を加えた 30 秒値を固定長ファイルへ書き、5 分集約と直近 10 分の系列（latest.recent）を更新する。
 */
class ResourceSampler
{
    /** @var array<int,array{ts:int,values:array}> */
    private array $ring = [];

    private ?array $prev1 = null;

    private ?int $prev1Ts = null;

    private ?array $prev30 = null;

    private ?int $prev30Ts = null;

    private ?int $next30 = null;

    private int $burstUntil = 0;

    private bool $inZone = false;

    /** @var array<string,mixed> 直近の 30 秒値（全項目） */
    private array $current = [];

    private array $disks = [];

    private array $sockets = [];

    /** @var array<string,array{count:int,required:bool,missing_since:?int}> */
    private array $processes = [];

    /** @var array<string,array<int,mixed>> 直近 10 分の 30 秒値 */
    private array $recent = ['t' => [], 'cpu' => [], 'iowait' => [], 'load1' => [], 'mem_available_pct' => [], 'swap_used_pct' => [], 'mem_used_pct' => [], 'disk_used_pct_max' => []];

    private ?int $bucketStart = null;

    /** @var array<int,array<string,mixed>> */
    private array $bucket = [];

    private int $burstSamples = 0;

    private int $cores = 1;

    public function __construct(private readonly MetricFile $file, private readonly array $cfg = []) {}

    public static function fromConfig(MetricFile $file): self
    {
        return new self($file, (array) config('monitoring.resources'));
    }

    private function c(string $key, mixed $default): mixed
    {
        return data_get($this->cfg, $key, $default);
    }

    /**
     * 1 ティック。snapshot は ProcReader の生値: [cpu, memory, load, pressure, diskstats, network]。
     * disks（statvfs 結果）と sockets は 30 秒境界でだけ使う（呼び出し側が必要時に取得して渡す）。
     *
     * @return array{wrote30:bool,burst:bool,aggregate:?array}
     */
    public function tick(int $ts, array $snapshot, ?callable $disks = null, ?callable $sockets = null, ?callable $processes = null): array
    {
        $interval = (int) $this->c('sample_seconds', 30);
        $this->cores = (int) ($snapshot['cpu']['cores'] ?? $this->cores);
        $result = ['wrote30' => false, 'burst' => false, 'aggregate' => null];
        if ($this->prev1 === null) {
            $this->prev1 = $this->prev30 = $snapshot;
            $this->prev1Ts = $this->prev30Ts = $ts;
            $this->next30 = (intdiv($ts, $interval) + 1) * $interval;

            return $result;
        }

        // 1 秒値とバースト
        if ((bool) $this->c('burst.enabled', true)) {
            $s1 = self::compute($this->prev1, $snapshot, max(1, $ts - $this->prev1Ts));
            $s1['disk_used_pct_max'] = $this->current['disk_used_pct_max'] ?? null;
            $this->inZone = $this->isWarningZone($s1);
            if ($this->inZone) {
                if ($ts > $this->burstUntil) {
                    foreach ($this->ring as $r) { // 警告域の前 pre_seconds 分
                        $this->file->appendBurst($r['ts'], $r['values']);
                        $this->burstSamples++;
                    }
                    $this->ring = [];
                }
                $this->burstUntil = $ts + (int) $this->c('burst.post_seconds', 120);
            }
            if ($ts <= $this->burstUntil) {
                $this->file->appendBurst($ts, $s1);
                $this->burstSamples++;
                $result['burst'] = true;
            } else {
                $this->ring[] = ['ts' => $ts, 'values' => $s1];
                $max = (int) $this->c('burst.pre_seconds', 120);
                if (count($this->ring) > $max) {
                    array_splice($this->ring, 0, count($this->ring) - $max);
                }
            }
        }
        $this->prev1 = $snapshot;
        $this->prev1Ts = $ts;

        // 30 秒値
        if ($ts >= $this->next30) {
            $slotTs = intdiv($ts, $interval) * $interval;
            $s = self::compute($this->prev30, $snapshot, max(1, $ts - $this->prev30Ts));
            if ($disks) {
                $this->disks = (array) $disks();
            }
            if ($sockets) {
                $sk = $sockets();
                if ($sk !== null) {
                    $this->sockets = (array) $sk;
                }
            }
            if ($processes) {
                $this->updateProcesses((array) $processes(), $slotTs);
            }
            $s['disk_used_pct_max'] = $this->disks ? max(array_column($this->disks, 'used_pct')) : null;
            $this->current = $s;
            $this->file->write($slotTs, $s);
            $this->pushRecent($slotTs, $s);
            $result['wrote30'] = true;
            $result['aggregate'] = $this->addToBucket($slotTs, $s);
            $this->prev30 = $snapshot;
            $this->prev30Ts = $ts;
            $this->next30 = $slotTs + $interval;
        }

        return $result;
    }

    public function isWarningZone(array $s): bool
    {
        $b = fn ($k, $d) => $this->c('burst.'.$k, $d);

        return ($s['cpu'] ?? 0) >= $b('cpu_pct', 70)
            || ($s['iowait'] ?? 0) >= $b('iowait_pct', 10)
            || (($s['mem_available_pct'] ?? 100) <= $b('mem_available_pct', 15))
            || (($s['load1'] ?? 0) > $this->cores * $b('load_per_core', 1.5));
    }

    /** 2 つの生スナップショットから使用率・速度を計算する。 @return array<string,mixed> */
    public static function compute(array $a, array $b, int $seconds): array
    {
        $out = [];
        $ca = $a['cpu'] ?? null;
        $cb = $b['cpu'] ?? null;
        if ($ca && $cb && ($dt = $cb['total'] - $ca['total']) > 0) {
            $p = fn ($k) => round(max(0, $cb[$k] - $ca[$k]) / $dt * 100, 2);
            $out['user'] = round($p('user') + $p('nice'), 2);
            $out['system'] = round($p('system') + $p('irq') + $p('softirq'), 2);
            $out['iowait'] = $p('iowait');
            $out['steal'] = $p('steal');
            $out['idle'] = $p('idle');
            $out['cpu'] = round(max(0, 100 - $out['idle'] - $out['iowait']), 2);
        }
        if ($m = $b['memory'] ?? null) {
            $out['mem_available_pct'] = round($m['available_kb'] / max(1, $m['total_kb']) * 100, 2);
            $out['mem_used_pct'] = round(100 - $out['mem_available_pct'], 2);
            $out['mem_total_gb'] = round($m['total_kb'] / 1048576, 1);
            $out['swap_used_pct'] = $m['swap_total_kb'] > 0 ? round(($m['swap_total_kb'] - $m['swap_free_kb']) / $m['swap_total_kb'] * 100, 2) : 0.0;
        }
        if ($l = $b['load'] ?? null) {
            $out += $l;
        }
        $psi = (array) ($b['pressure'] ?? []);
        $out['psi_cpu'] = $psi['cpu'] ?? null;
        $out['psi_io'] = $psi['io'] ?? null;
        $out['psi_mem'] = $psi['memory'] ?? null;

        $io = [];
        foreach ((array) ($b['diskstats'] ?? []) as $dev => $d) {
            $p = $a['diskstats'][$dev] ?? null;
            if (! $p) {
                continue;
            }
            $io[$dev] = [
                'r_iops' => round(max(0, $d['reads'] - $p['reads']) / $seconds, 1),
                'w_iops' => round(max(0, $d['writes'] - $p['writes']) / $seconds, 1),
                'r_mbs' => round(max(0, $d['read_sectors'] - $p['read_sectors']) * 512 / $seconds / 1048576, 2),
                'w_mbs' => round(max(0, $d['write_sectors'] - $p['write_sectors']) * 512 / $seconds / 1048576, 2),
                'util' => round(min(100, max(0, $d['io_ms'] - $p['io_ms']) / ($seconds * 1000) * 100), 1),
            ];
        }
        $out['io'] = $io;
        $out['io_util_max'] = $io ? max(array_column($io, 'util')) : null;

        $net = [];
        foreach ((array) ($b['network'] ?? []) as $if => $n) {
            $p = $a['network'][$if] ?? null;
            if (! $p) {
                continue;
            }
            $net[$if] = ['rx_bps' => (int) round(max(0, $n['rx_bytes'] - $p['rx_bytes']) / $seconds), 'tx_bps' => (int) round(max(0, $n['tx_bytes'] - $p['tx_bytes']) / $seconds),
                'errs' => max(0, ($n['rx_errs'] + $n['tx_errs']) - ($p['rx_errs'] + $p['tx_errs']))];
        }
        $out['net'] = $net;
        $out['net_rx_mbps'] = $net ? round(array_sum(array_column($net, 'rx_bps')) * 8 / 1e6, 3) : null;
        $out['net_tx_mbps'] = $net ? round(array_sum(array_column($net, 'tx_bps')) * 8 / 1e6, 3) : null;

        return $out;
    }

    private function pushRecent(int $ts, array $s): void
    {
        $this->recent['t'][] = $ts;
        foreach (array_keys($this->recent) as $k) {
            if ($k !== 't') {
                $this->recent[$k][] = isset($s[$k]) ? round((float) $s[$k], 2) : null;
            }
        }
        $keep = intdiv(600, (int) $this->c('sample_seconds', 30)) + 1; // 10 分
        foreach (array_keys($this->recent) as $k) {
            if (count($this->recent[$k]) > $keep) {
                $this->recent[$k] = array_slice($this->recent[$k], -$keep);
            }
        }
    }

    /** 5 分境界（UTC）をまたいだら直前の枠の集約を返す */
    private function addToBucket(int $ts, array $s): ?array
    {
        $start = intdiv($ts, 300) * 300;
        $done = null;
        if ($this->bucketStart !== null && $start !== $this->bucketStart && $this->bucket) {
            $done = self::aggregate($this->bucketStart, $this->bucket, $this->disks, $this->sockets, $this->cores);
            $this->bucket = [];
        }
        $this->bucketStart = $start;
        $this->bucket[] = $s;

        return $done;
    }

    /** 未完了の現在枠も含めて取り出す（終了時） */
    public function flushBucket(): ?array
    {
        if ($this->bucketStart === null || ! $this->bucket) {
            return null;
        }
        $agg = self::aggregate($this->bucketStart, $this->bucket, $this->disks, $this->sockets, $this->cores);
        $this->bucket = [];

        return $agg;
    }

    /** @param array<int,array<string,mixed>> $samples */
    public static function aggregate(int $bucketStart, array $samples, array $disks, array $sockets, int $cores): array
    {
        $col = fn (string $k) => array_values(array_filter(array_map(fn ($s) => isset($s[$k]) ? (float) $s[$k] : null, $samples), fn ($v) => $v !== null));
        $avg = fn (string $k) => ($v = $col($k)) ? round(array_sum($v) / count($v), 2) : null;
        $max = fn (string $k) => ($v = $col($k)) ? round(max($v), 2) : null;
        $min = fn (string $k) => ($v = $col($k)) ? round(min($v), 2) : null;
        $last = end($samples) ?: [];

        $io = [];
        foreach ($samples as $s) {
            foreach ((array) ($s['io'] ?? []) as $dev => $d) {
                foreach ($d as $k => $v) {
                    $io[$dev][$k][] = $v;
                }
            }
        }
        $ioJson = [];
        foreach ($io as $dev => $m) {
            foreach ($m as $k => $vals) {
                $ioJson[$dev][$k.'_avg'] = round(array_sum($vals) / count($vals), 2);
                $ioJson[$dev][$k.'_max'] = round(max($vals), 2);
            }
        }
        $net = [];
        foreach ($samples as $s) {
            foreach ((array) ($s['net'] ?? []) as $if => $d) {
                $net[$if]['rx'][] = $d['rx_bps'];
                $net[$if]['tx'][] = $d['tx_bps'];
            }
        }
        $netJson = [];
        foreach ($net as $if => $m) {
            $netJson[$if] = ['rx_bps_avg' => (int) round(array_sum($m['rx']) / count($m['rx'])), 'rx_bps_max' => max($m['rx']), 'tx_bps_avg' => (int) round(array_sum($m['tx']) / count($m['tx'])), 'tx_bps_max' => max($m['tx'])];
        }

        return [
            'bucket_start' => $bucketStart,
            'sample_count' => count($samples),
            'cpu_avg' => $avg('cpu'), 'cpu_max' => $max('cpu'),
            'iowait_avg' => $avg('iowait'), 'iowait_max' => $max('iowait'),
            'steal_avg' => $avg('steal'),
            'load1_avg' => $avg('load1'), 'load1_max' => $max('load1'),
            'mem_used_pct_avg' => $avg('mem_used_pct'), 'mem_used_pct_max' => $max('mem_used_pct'),
            'swap_used_pct_max' => $max('swap_used_pct'),
            'disk_used_pct_max' => $max('disk_used_pct_max'),
            'psi_cpu_avg' => $avg('psi_cpu'), 'psi_io_avg' => $avg('psi_io'), 'psi_mem_avg' => $avg('psi_mem'),
            'disk_json' => array_map(fn ($d) => array_intersect_key($d, array_flip(['mount', 'fstype', 'size_gb', 'free_gb', 'used_pct', 'inode_used_pct'])), $disks),
            'io_json' => $ioJson,
            'net_json' => $netJson,
            'extra_json' => [
                'min' => ['cpu' => $min('cpu'), 'mem_available_pct' => $min('mem_available_pct')],
                'last' => array_intersect_key($last, array_flip(['cpu', 'iowait', 'load1', 'load5', 'load15', 'mem_used_pct', 'swap_used_pct', 'disk_used_pct_max'])),
                'sockets' => $sockets ?: null,
                'cores' => $cores,
            ],
        ];
    }

    /** ノードカード・しきい値判定用の直近値（MetricsIngestService が ins_monitor_nodes.latest_json に保存） */
    public function latestPayload(int $ts): array
    {
        $cur = $this->current;
        unset($cur['io'], $cur['net']);

        return [
            'ts' => $ts,
            'cores' => $this->cores,
            'current' => $cur,
            'disks' => $this->disks,
            'sockets' => $this->sockets ?: null,
            'processes' => $this->processes ?: null,
            'recent' => $this->recent,
            'burst' => $this->inZone || $ts <= $this->burstUntil,
            'burst_samples' => $this->burstSamples,
        ];
    }

    public function hasCurrent(): bool
    {
        return (bool) $this->current;
    }

    /**
     * プロセス件数を反映し、必須プロセスが 0 件になった時刻を保持する（復帰したら消す）。
     *
     * @param  array<string,int>  $counts
     */
    private function updateProcesses(array $counts, int $ts): void
    {
        $required = (array) $this->c('required_processes_resolved', []);
        foreach ($counts as $key => $n) {
            $prev = $this->processes[$key]['missing_since'] ?? null;
            $this->processes[$key] = ['count' => (int) $n, 'required' => in_array($key, $required, true), 'missing_since' => $n > 0 ? null : ($prev ?? $ts)];
        }
    }

    /** このサーバーで必須のプロセス（.env の MONITOR_NODE_PROCESSES、未設定なら役割の既定値） @return string[] */
    public static function requiredProcesses(array $cfg, string $role): array
    {
        $req = (array) ($cfg['required_processes'] ?? []);
        if (! $req) {
            $req = (array) ($cfg['role_processes'][$role] ?? []);
        }

        return array_values(array_intersect($req, array_keys((array) ($cfg['processes'] ?? []))));
    }
}
