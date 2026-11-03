<?php

namespace App\Console\Commands\Monitoring;

use App\Monitoring\Services\MonitorHealthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class HealthCommand extends Command
{
    protected $signature = 'monitor:health {--json} {--external-heartbeat : ホスト外死活監視へ heartbeat を送る}';

    protected $description = '運用監視: 収集器・実行器・通知待ち・削除遅延・容量の状態を表示する';

    public function handle(MonitorHealthService $health): int
    {
        $s = $health->snapshot();
        if ($this->option('external-heartbeat') && ($url = config('monitoring.notification.external_heartbeat_url'))) {
            try {
                Http::timeout(5)->get($url);
            } catch (\Throwable $e) {
                $this->warn('external heartbeat failed: '.get_class($e));
            }
        }
        if ($this->option('json')) {
            $this->line(json_encode($s, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }
        $d = $s['deployment'];
        $this->info("deployment={$d['id']} client={$d['client']} env={$d['environment']} node={$d['node']} owner={$d['owner']}");
        $this->line('checker: '.$s['checker']['state'].' last_run='.($s['checker']['last_run_at'] ?? '-').' stale_checks='.count($s['checker']['stale_checks']));
        foreach ($s['collectors'] as $c) {
            $this->line(sprintf('collector %s@%s: %s (%s)', $c['site'], $c['node'], $c['state'], $c['age_seconds'] === null ? 'never' : $c['age_seconds'].'s'));
        }
        $n = $s['notifications'];
        $this->line(sprintf('notifications: pending_oldest=%ds blocked=%d route=%s disabled=%s fallback=%s external_hb=%s', $n['oldest_pending_seconds'], $n['blocked_count'], $n['route_configured'] ? 'ok' : 'MISSING', $n['globally_disabled'] ? 'YES' : 'no', $n['fallback_contact_configured'] ? 'ok' : 'MISSING', $n['external_heartbeat_configured'] ? 'ok' : 'MISSING'));
        $st = $s['storage'];
        $this->line(sprintf('storage: spool=%.1f%% retention_lag=%dh emergency=%s', $st['spool_ratio'] * 100, $st['retention_lag_hours'], $st['emergency'] ? 'YES' : 'no'));
        $this->line('source_configured: '.($s['source_configured'] ? 'yes' : 'NO'));
        foreach ($health->banners($s) as $b) {
            $this->line('['.$b['level'].'] '.$b['text']);
        }

        return self::SUCCESS;
    }
}
