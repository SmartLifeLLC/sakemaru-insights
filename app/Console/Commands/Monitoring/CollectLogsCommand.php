<?php

namespace App\Console\Commands\Monitoring;

use App\Monitoring\Collectors\FileLogCollector;
use App\Monitoring\Collectors\LaravelLogParser;
use App\Monitoring\Collectors\Shipper;
use App\Monitoring\Collectors\Spool;
use Illuminate\Console\Command;

class CollectLogsCommand extends Command
{
    protected $signature = 'monitor:collect-logs {--collector=* : collector_uuid で限定} {--no-ship : 退避のみ行い送信しない}';

    protected $description = '運用監視: 自ノードの許可済みログファイルを増分収集し、退避→送信→ACK解放する（15〜60秒間隔）';

    public function handle(LaravelLogParser $parser, Shipper $shipper): int
    {
        $configs = (array) config('monitoring.collectors', []);
        $only = $this->option('collector');
        $node = (string) config('monitoring.deployment.node_code');
        $ran = 0;
        foreach ($configs as $cfg) {
            if ($only && ! in_array($cfg['collector_uuid'] ?? '', $only, true)) {
                continue;
            }
            if (($cfg['node_code'] ?? null) !== $node && ! $only) {
                continue; // 自ノードの収集器だけ
            }
            $spool = Spool::forCollector($cfg['collector_uuid']);
            $lock = $this->lock($cfg['collector_uuid']);
            if (! $lock) {
                $this->warn("{$cfg['application_code']}@{$cfg['node_code']}: 既に実行中");

                continue;
            }
            try {
                $collector = new FileLogCollector($cfg, $parser, $spool, (string) config('monitoring.collector.state_dir'));
                $r = $collector->collect();
                $this->line(sprintf('%s@%s collected events=%d bytes=%d spool=%.1f%%', $cfg['application_code'], $cfg['node_code'], $r['events'], $r['bytes'], $spool->ratio() * 100));
                foreach ($r['files'] as $path => $info) {
                    $this->line('  '.$path.' => '.(is_array($info) ? json_encode($info) : $info));
                }
                if (! $this->option('no-ship')) {
                    $s = $shipper->ship($cfg, $spool);
                    $this->line(sprintf('  shipped accepted=%d duplicate=%d conflict=%d rejected=%d files=%d', $s['accepted'], $s['duplicate'], $s['conflict'], $s['rejected'], $s['files']));
                    $shipper->heartbeat($cfg, ['spool_ratio' => round($spool->ratio(), 3), 'files' => $r['files']]);
                }
                $ran++;
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
        if ($ran === 0) {
            $this->warn("ノード {$node} に対応する収集器設定がありません（config/monitoring.php collectors）。");
        }

        return self::SUCCESS;
    }

    /** @return resource|false */
    private function lock(string $uuid)
    {
        $dir = rtrim((string) config('monitoring.collector.state_dir'), '/');
        @mkdir($dir, 0750, true);
        $fh = @fopen($dir.'/collector-'.$uuid.'.lock', 'c');
        if (! $fh) {
            return false;
        }
        if (! flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);

            return false;
        }

        return $fh;
    }
}
