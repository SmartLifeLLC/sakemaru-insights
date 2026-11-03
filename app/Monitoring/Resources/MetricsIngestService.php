<?php

namespace App\Monitoring\Resources;

use App\Models\Monitoring\Node;
use App\Models\Monitoring\ServerMetric;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * 資源データの受け取り（v2 第5章）。ノードは .env の MONITOR_NODE_CODE で初回送信時に自動登録する（画面からの手入力は不要）。
 * 画面で変えた表示名・有効/無効・しきい値は上書きしない。5 分集約は (node, bucket_start) で冪等（V-064）。
 */
class MetricsIngestService
{
    public const NODE_CODE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/';

    private const ROLES = ['web', 'batch', 'db', 'other'];

    private const NUMERIC = ['cpu_avg', 'cpu_max', 'iowait_avg', 'iowait_max', 'steal_avg', 'load1_avg', 'load1_max', 'mem_used_pct_avg', 'mem_used_pct_max', 'swap_used_pct_max', 'disk_used_pct_max', 'psi_cpu_avg', 'psi_io_avg', 'psi_mem_avg'];

    /** @return array{node_id:int,stored:int,rejected:int,thresholds:array} */
    public function ingest(array $payload, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $n = (array) ($payload['node'] ?? []);
        $code = (string) ($n['code'] ?? '');
        if (! preg_match(self::NODE_CODE_PATTERN, $code)) {
            throw new \InvalidArgumentException('invalid_node_code');
        }
        $role = in_array($n['role'] ?? 'web', self::ROLES, true) ? ($n['role'] ?? 'web') : 'other';
        $display = mb_substr(trim((string) ($n['display_name'] ?? '')) ?: $code, 0, 120);
        $os = array_intersect_key((array) ($n['os'] ?? []), array_flip(['kernel', 'cores', 'mem_total_gb', 'php', 'hostname', 'collector_version']));

        $node = DB::connection('monitor_store')->transaction(function () use ($code, $role, $display, $os, $now) {
            $node = Node::query()->where('node_code', $code)->lockForUpdate()->first();
            if (! $node) {
                try {
                    return Node::query()->create(['node_code' => $code, 'display_name' => $display, 'role' => $role, 'enabled' => true, 'os_info_json' => $os, 'registered_from' => 'env', 'last_heartbeat_at' => $now]);
                } catch (\Illuminate\Database\QueryException) {
                    $node = Node::query()->where('node_code', $code)->firstOrFail();
                }
            }
            $node->role = $role;
            $node->os_info_json = $os ?: $node->os_info_json;
            if (! $node->updated_user_id) {
                $node->display_name = $display; // 画面で変更していなければ .env の表示名に追随
            }
            $node->last_heartbeat_at = $now;
            $node->save();

            return $node;
        });

        $latest = $payload['latest'] ?? null;
        if (is_array($latest) && $latest) {
            $node->latest_json = $this->sanitizeLatest($latest, $now);
            $node->save();
        }

        $stored = 0;
        $rejected = 0;
        if ($node->enabled) {
            $days = (int) config('monitoring.resources.db_days', 365);
            foreach (array_slice((array) ($payload['aggregates'] ?? []), 0, 2016) as $a) {
                $bucket = (int) ($a['bucket_start'] ?? 0);
                if ($bucket % 300 !== 0 || $bucket > $now->getTimestamp() || $bucket < $now->subDays(8)->getTimestamp()) {
                    $rejected++;

                    continue;
                }
                $row = ['sample_count' => max(0, min(65535, (int) ($a['sample_count'] ?? 0)))];
                foreach (self::NUMERIC as $k) {
                    $row[$k] = isset($a[$k]) && is_numeric($a[$k]) ? round((float) $a[$k], 2) : null;
                }
                foreach (['disk_json', 'io_json', 'net_json', 'extra_json'] as $k) {
                    $row[$k] = is_array($a[$k] ?? null) ? $a[$k] : null;
                }
                $start = CarbonImmutable::createFromTimestamp($bucket, 'UTC');
                $row['expires_at'] = $start->addDays($days);
                ServerMetric::query()->updateOrCreate(['node_id' => $node->id, 'bucket_start' => $start], $row);
                $stored++;
            }
            if ($stored) {
                Node::query()->where('id', $node->id)->update(['last_metrics_at' => $now]);
            }
        }

        return ['node_id' => (int) $node->id, 'stored' => $stored, 'rejected' => $rejected, 'thresholds' => ThresholdEvaluator::resolve($node->thresholds_json)];
    }

    /** 送信側の値を信用しすぎない（サイズ・型の制限） */
    private function sanitizeLatest(array $l, CarbonImmutable $now): array
    {
        $num = fn ($v) => is_numeric($v) ? round((float) $v, 3) : null;
        $cur = [];
        foreach ((array) ($l['current'] ?? []) as $k => $v) {
            if (is_string($k) && strlen($k) <= 32 && (is_numeric($v) || $v === null)) {
                $cur[$k] = $num($v);
            }
        }
        $recent = ['t' => []];
        $t = array_slice(array_map('intval', (array) ($l['recent']['t'] ?? [])), -25);
        $recent['t'] = $t;
        foreach (['cpu', 'iowait', 'load1', 'mem_available_pct', 'swap_used_pct', 'mem_used_pct', 'disk_used_pct_max'] as $k) {
            $recent[$k] = array_map($num, array_slice((array) ($l['recent'][$k] ?? []), -count($t)));
        }
        $disks = [];
        foreach (array_slice((array) ($l['disks'] ?? []), 0, 32) as $d) {
            $disks[] = ['mount' => mb_substr((string) ($d['mount'] ?? '?'), 0, 200), 'fstype' => mb_substr((string) ($d['fstype'] ?? ''), 0, 20),
                'size_gb' => $num($d['size_gb'] ?? null), 'free_gb' => $num($d['free_gb'] ?? null), 'used_pct' => $num($d['used_pct'] ?? null), 'inode_used_pct' => $num($d['inode_used_pct'] ?? null)];
        }
        $ts = (int) ($l['ts'] ?? 0);
        // 送信側の時計が大きくずれていても、受信時刻より未来にはしない
        $ts = min($ts ?: $now->getTimestamp(), $now->getTimestamp());

        return ['ts' => $ts, 'received_at' => $now->getTimestamp(), 'cores' => max(1, (int) ($l['cores'] ?? 1)), 'current' => $cur, 'disks' => $disks,
            'processes' => $this->sanitizeProcesses($l['processes'] ?? null, $now),
            'sockets' => is_array($l['sockets'] ?? null) ? array_map($num, array_slice($l['sockets'], 0, 8)) : null, 'recent' => $recent,
            'burst' => (bool) ($l['burst'] ?? false), 'burst_samples' => (int) ($l['burst_samples'] ?? 0)];
    }

    /** 既知のプロセスキーだけ受け取る（件数・必須・0 件になった時刻） */
    private function sanitizeProcesses(mixed $p, CarbonImmutable $now): ?array
    {
        if (! is_array($p)) {
            return null;
        }
        $known = array_keys((array) config('monitoring.resources.processes', []));
        $out = [];
        foreach ($p as $k => $v) {
            if (in_array($k, $known, true) && is_array($v)) {
                $since = isset($v['missing_since']) && is_numeric($v['missing_since']) ? min((int) $v['missing_since'], $now->getTimestamp()) : null;
                $out[$k] = ['count' => max(0, (int) ($v['count'] ?? 0)), 'required' => (bool) ($v['required'] ?? false), 'missing_since' => $since];
            }
        }

        return $out ?: null;
    }
}
