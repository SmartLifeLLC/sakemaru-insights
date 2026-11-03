<?php

namespace App\Console\Commands\Monitoring;

use App\Monitoring\Resources\MetricFile;
use App\Monitoring\Resources\MetricsShipper;
use App\Monitoring\Resources\ProcReader;
use App\Monitoring\Resources\ResourceSampler;
use Illuminate\Console\Command;

/**
 * 資源収集（常駐、v2 第5章）。各サーバーで systemd の sakemaru-monitor-resources@.service として 1 プロセスだけ動かす。
 * /proc 直読み・外部コマンドは 60 秒に 1 回（df -i / ss -s）だけ。CPU・I/O の優先度は低く設定する（Nice / IOSchedulingClass=idle）。
 */
class SampleResourcesCommand extends Command
{
    protected $signature = 'monitor:sample-resources {--once : 1 秒間隔の 2 回読み取りで現在値を表示して終了} {--seconds=0 : 指定秒数で終了（0 は無期限）}';

    protected $description = '運用監視: サーバー資源（CPU・メモリ・ディスク・負荷）を 30 秒間隔で記録し、警告域では 1 秒値を記録する';

    private bool $running = true;

    public function handle(MetricsShipper $shipper): int
    {
        $reader = ProcReader::fromConfig();
        if ($reader->cpu() === null) {
            $this->error('/proc を読めません（Linux 以外、または MONITOR_PROC_ROOT の誤り）。');

            return self::FAILURE;
        }
        $node = $this->nodeInfo($reader);
        if ($this->option('once')) {
            return $this->once($reader, $node);
        }
        $lock = $this->lock($node['code']);
        if (! $lock) {
            $this->warn('既に実行中です。');

            return self::SUCCESS;
        }
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->running = false);
            pcntl_signal(SIGINT, fn () => $this->running = false);
        }
        $file = MetricFile::forNode($node['code']);
        $cfg = (array) config('monitoring.resources');
        $cfg['required_processes_resolved'] = ResourceSampler::requiredProcesses($cfg, $node['role']);
        $sampler = new ResourceSampler($file, $cfg);
        $procDefs = (array) ($cfg['processes'] ?? []);
        $procEvery = max(30, (int) ($cfg['process_scan_seconds'] ?? 60));
        $nextProc = 0;
        $poll = ($cfg['burst']['enabled'] ?? true) ? max(1, (int) ($cfg['burst']['poll_seconds'] ?? 1)) : (int) ($cfg['sample_seconds'] ?? 30);
        $limit = (int) $this->option('seconds');
        $started = time();
        $nextShip = $started + 30;
        $nextAux = 0;
        $inodes = [];
        $mounts = $reader->mounts();
        $queue = [];
        $pruneDate = null;
        $this->info("資源収集を開始しました（ノード {$node['code']}、{$poll} 秒ごと）。");

        while ($this->running && (! $limit || time() - $started < $limit)) {
            $ts = time();
            $aux = $ts >= $nextAux;
            if ($aux) {
                $nextAux = $ts + (int) ($cfg['socket_stats_seconds'] ?? 60);
                $inodes = ProcReader::inodeUsage();
                $mounts = $reader->mounts();
            }
            $r = $sampler->tick($ts, $this->snapshot($reader), fn () => $reader->diskUsage($mounts, $inodes), $aux ? fn () => ProcReader::socketSummary() : null, $ts >= $nextProc ? function () use ($reader, $procDefs, &$nextProc, $ts, $procEvery) {
                $nextProc = $ts + $procEvery; // 1 分ごと

                return $reader->processes($procDefs);
            } : null);
            if ($r['aggregate']) {
                $queue[] = $r['aggregate'];
            }
            if ($ts >= $nextShip && $sampler->hasCurrent()) {
                $s = $shipper->ship($node, $sampler->latestPayload($ts), $queue);
                $queue = [];
                $nextShip = $ts + (int) ($cfg['ship_seconds'] ?? 60);
                if (! $s['ok'] && $this->output->isVerbose()) {
                    $this->warn("送信に失敗しました（退避 {$s['pending']} 件）。");
                }
            }
            $today = MetricFile::jstDate($ts);
            if ($pruneDate !== $today) {
                $file->prune((int) ($cfg['local_days'] ?? 60), $ts);
                $pruneDate = $today;
            }
            $this->sleepUntil($ts + $poll);
        }
        // 終了（SIGTERM・RuntimeMaxSec）時に未送信の集約を送る。失敗しても退避ファイルに残る
        if ($sampler->hasCurrent()) {
            if ($agg = $sampler->flushBucket()) {
                $queue[] = $agg;
            }
            $shipper->ship($node, $sampler->latestPayload(time()), $queue);
        }
        flock($lock, LOCK_UN);
        fclose($lock);

        return self::SUCCESS;
    }

    private function snapshot(ProcReader $r): array
    {
        return ['cpu' => $r->cpu(), 'memory' => $r->memory(), 'load' => $r->load(), 'pressure' => $r->pressure(), 'diskstats' => $r->diskstats(), 'network' => $r->network()];
    }

    private function sleepUntil(int $ts): void
    {
        while ($this->running && microtime(true) < $ts) {
            usleep((int) min(250000, max(1000, ($ts - microtime(true)) * 1e6)));
        }
    }

    private function once(ProcReader $reader, array $node): int
    {
        $a = $this->snapshot($reader);
        $t0 = microtime(true);
        sleep(1);
        $b = $this->snapshot($reader);
        $s = ResourceSampler::compute($a, $b, 1);
        $disks = $reader->diskUsage($reader->mounts(), ProcReader::inodeUsage());
        $this->line('ノード: '.$node['code'].'（'.$node['role'].'、'.$node['display_name'].'）');
        $this->table(['項目', '値'], [
            ['CPU 使用率', ($s['cpu'] ?? '-').'%（user '.($s['user'] ?? '-').' / system '.($s['system'] ?? '-').' / iowait '.($s['iowait'] ?? '-').' / steal '.($s['steal'] ?? '-').'）'],
            ['load', ($s['load1'] ?? '-').' / '.($s['load5'] ?? '-').' / '.($s['load15'] ?? '-').'（'.($b['cpu']['cores'] ?? '?').' コア）'],
            ['メモリ', ($s['mem_used_pct'] ?? '-').'% 使用（空き '.($s['mem_available_pct'] ?? '-').'%、'.($s['mem_total_gb'] ?? '-').'GB）'],
            ['スワップ', ($s['swap_used_pct'] ?? '-').'%'],
            ['PSI some avg10', 'cpu '.($s['psi_cpu'] ?? '-').' / io '.($s['psi_io'] ?? '-').' / mem '.($s['psi_mem'] ?? '-')],
        ]);
        $this->table(['マウント', '種別', '使用率', '空き', 'inode'], array_map(fn ($d) => [$d['mount'], $d['fstype'], $d['used_pct'].'%', $d['free_gb'].'GB', $d['inode_used_pct'] === null ? '-' : $d['inode_used_pct'].'%'], $disks));
        $cfg = (array) config('monitoring.resources');
        $req = ResourceSampler::requiredProcesses($cfg, $node['role']);
        $counts = $reader->processes((array) ($cfg['processes'] ?? []));
        $this->table(['プロセス', '件数', 'このサーバーで必須'], array_map(fn ($k) => [($cfg['processes'][$k]['label'] ?? $k), $counts[$k], in_array($k, $req, true) ? '必須' : '—'], array_keys($counts)));
        foreach ($req as $k) {
            if (($counts[$k] ?? 0) === 0) {
                $this->warn(($cfg['processes'][$k]['label'] ?? $k).' が動いていません（必須）。MONITOR_NODE_PROCESSES を確認してください。');
            }
        }
        $this->line(sprintf('読み取り時間: %.2fms', (microtime(true) - $t0 - 1) * 1000));

        return self::SUCCESS;
    }

    private function nodeInfo(ProcReader $reader): array
    {
        $n = (array) config('monitoring.resources.node');
        $cpu = $reader->cpu();
        $mem = $reader->memory();

        return [
            'code' => (string) ($n['code'] ?? 'local'),
            'role' => (string) ($n['role'] ?? 'web'),
            'display_name' => (string) ($n['display_name'] ?: ($n['code'] ?? 'local')),
            'os' => ['kernel' => $reader->kernel(), 'cores' => $cpu['cores'] ?? null, 'mem_total_gb' => $mem ? round($mem['total_kb'] / 1048576, 1) : null,
                'php' => PHP_VERSION, 'hostname' => gethostname() ?: null, 'collector_version' => '2.0'],
        ];
    }

    /** @return resource|false */
    private function lock(string $code)
    {
        $dir = rtrim((string) config('monitoring.collector.state_dir'), '/');
        @mkdir($dir, 0750, true);
        $fh = @fopen($dir.'/resources-'.preg_replace('/[^A-Za-z0-9_.-]/', '_', $code).'.lock', 'c');
        if (! $fh || ! flock($fh, LOCK_EX | LOCK_NB)) {
            return false;
        }

        return $fh;
    }
}
