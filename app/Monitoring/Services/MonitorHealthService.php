<?php

namespace App\Monitoring\Services;

use App\Models\Monitoring\Check as CheckModel;
use App\Models\Monitoring\CheckRun;
use App\Models\Monitoring\Collector;
use App\Models\Monitoring\LogEntry;
use App\Models\Monitoring\Notification;
use Carbon\CarbonImmutable;

/**
 * 監視自身の健全性（仕様書 4.1 MonitorHealth / 13.6）。正常未確認を正常と表示しない。
 */
class MonitorHealthService
{
    public function __construct(private readonly SiteRegistry $sites, private readonly NotificationDispatcher $dispatcher) {}

    /** @return array<string,mixed> */
    public function snapshot(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $cfg = config('monitoring');

        $collectors = Collector::query()->get()->map(function (Collector $c) use ($now) {
            $site = $this->sites->siteById((int) $c->site_id);
            $age = $c->last_heartbeat_at ? (int) CarbonImmutable::instance($c->last_heartbeat_at)->diffInSeconds($now) : null;

            return [
                'uuid' => $c->collector_uuid,
                'site' => $site?->application_code,
                'node' => $c->node_code,
                'internal' => str_contains((string) $c->node_code, '@'),
                'last_heartbeat_at' => $c->last_heartbeat_at,
                'age_seconds' => $age,
                'state' => $age === null ? 'NEVER' : ($age > 600 ? 'ERROR' : ($age > 300 ? 'WARNING' : 'OK')),
            ];
        })->values()->all();

        $lastRun = CheckRun::query()->orderByDesc('id')->first(['started_at', 'finished_at', 'execution_status']);
        $checkerAge = $lastRun ? (int) CarbonImmutable::instance($lastRun->started_at)->diffInSeconds($now) : null;
        $staleChecks = CheckModel::query()->where('enabled', true)->where('implemented', true)
            ->get()->filter(fn (CheckModel $c) => app(FeatureRegistry::class)->gate($c)['active'])->filter(function (CheckModel $c) use ($now) {
                $limit = (int) $c->interval_seconds + (int) $c->grace_seconds;
                $last = $c->last_completed_at ? CarbonImmutable::instance($c->last_completed_at) : null;

                return ! $last || $last->diffInSeconds($now) > $limit;
            })->map(fn ($c) => ['check_key' => $c->check_key, 'site' => $this->sites->siteById((int) $c->site_id)?->application_code, 'last_completed_at' => $c->last_completed_at, 'paused_until' => $c->paused_until, 'pause_reason' => $c->pause_reason])->values()->all();

        $oldestPending = Notification::query()->whereIn('status', ['PENDING', 'RETRY_WAIT', 'SENDING'])->orderBy('id')->first(['created_at', 'status']);
        $pendingAge = $oldestPending ? (int) CarbonImmutable::instance($oldestPending->created_at)->diffInSeconds($now) : 0;
        $blocked = Notification::query()->where('status', 'BLOCKED')->limit(50)->count();

        $spool = $this->spoolUsage();
        $retentionLag = $this->retentionLagHours($now);
        $emergency = $this->emergencyLogTail();

        return [
            'as_of' => $now,
            'deployment' => ['id' => $this->sites->deploymentId(), 'client' => $this->sites->clientName(), 'environment' => $this->sites->environmentLabel(), 'node' => $this->sites->nodeCode(), 'owner' => $cfg['deployment']['check_owner_node'] ?? null],
            'collectors' => $collectors,
            'checker' => ['last_run_at' => $lastRun?->started_at, 'age_seconds' => $checkerAge, 'last_status' => $lastRun?->execution_status, 'state' => $checkerAge === null ? 'NEVER' : ($checkerAge > 900 ? 'ERROR' : ($checkerAge > 420 ? 'WARNING' : 'OK')), 'stale_checks' => $staleChecks],
            'notifications' => [
                'oldest_pending_seconds' => $pendingAge,
                'pending_state' => $pendingAge > ($cfg['notification']['stale_pending_seconds'] ?? 300) ? 'ERROR' : 'OK',
                'blocked_count' => $blocked,
                'globally_disabled' => $this->dispatcher->isGloballyDisabled($now),
                'disable_reason' => $cfg['notification']['global_disable_reason'] ?? null,
                'fallback_contact_configured' => ! empty($cfg['notification']['fallback_contact']),
                'external_heartbeat_configured' => ! empty($cfg['notification']['external_heartbeat_url']),
                'route_configured' => app(SecretStore::class)->get('slack.webhook_url') !== null, // 画面の設定 > .env
                'route_display' => $cfg['notification']['routes'][$cfg['notification']['default_route']]['display_name'] ?? null,
            ],
            'storage' => $spool + ['retention_lag_hours' => $retentionLag, 'emergency' => $emergency],
            'source_configured' => (bool) config('database.connections.monitor_source.database'),
        ];
    }

    /** 画面上部の固定バナー（仕様書 11.1）。 */
    public function banners(?array $snapshot = null): array
    {
        $s = $snapshot ?? $this->snapshot();
        $b = [];
        $pause = app(NotificationSettings::class)->pause();
        if ($pause) {
            $b[] = ['level' => 'warning', 'text' => 'アラート停止中 〜'.$pause['until']->setTimezone('Asia/Tokyo')->format('H:i').'（'.($pause['actor_name'] ?: '—').'）: '.$pause['reason'].' — 通知は保留し、再開後にまとめて送ります。', 'kind' => 'pause'];
        } elseif ($s['notifications']['globally_disabled']) {
            $b[] = ['level' => 'error', 'text' => 'ERROR通知が管理者により全面停止中です'.($s['notifications']['disable_reason'] ? '（'.$s['notifications']['disable_reason'].'）' : '').'。'];
        }
        if (! $s['notifications']['route_configured']) {
            $b[] = ['level' => 'error', 'text' => 'Slack の Webhook が未設定です（通知設定の「接続情報」または .env）。エラーは画面とメール・電話（設定時）でのみ確認できます。'];
        }
        if ($s['notifications']['pending_state'] === 'ERROR') {
            $b[] = ['level' => 'error', 'text' => '通知が '.intdiv($s['notifications']['oldest_pending_seconds'], 60).' 分以上未送信です。配送ワーカーまたは Slack 到達性を確認してください。'];
        }
        if ($s['notifications']['blocked_count'] > 0) {
            $b[] = ['level' => 'error', 'text' => '配送不可（BLOCKED）の通知が '.$s['notifications']['blocked_count'].' 件あります。通知経路の設定を確認してください。'];
        }
        if ($s['checker']['state'] !== 'OK') {
            $b[] = ['level' => $s['checker']['state'] === 'NEVER' ? 'warning' : 'error', 'text' => $s['checker']['state'] === 'NEVER' ? '監視実行器がまだ一度も実行されていません（monitor:run-checks）。' : '監視実行器の最終実行から '.intdiv($s['checker']['age_seconds'], 60).' 分経過しています。表示データが古い可能性があります。'];
        }
        foreach ($s['collectors'] as $c) {
            if (! $c['internal'] && in_array($c['state'], ['ERROR', 'NEVER'], true)) {
                $b[] = ['level' => 'error', 'text' => "収集器 {$c['site']}@{$c['node']} の heartbeat が".($c['state'] === 'NEVER' ? '未受信' : intdiv($c['age_seconds'], 60).'分停止').'です。'];
            }
        }
        if (! $s['notifications']['fallback_contact_configured']) {
            $b[] = ['level' => 'info', 'text' => '代替通知先が未設定です（Slack 障害時の連絡手段）。本番移行前に運用責任者が指定してください。'];
        }
        if (! $s['notifications']['external_heartbeat_configured']) {
            $b[] = ['level' => 'info', 'text' => 'ホスト外の死活監視が未設定です。サーバー全停止は検知できません。'];
        }
        if (($s['storage']['spool_ratio'] ?? 0) >= 0.85) {
            $b[] = ['level' => 'error', 'text' => '退避領域の使用率が '.round($s['storage']['spool_ratio'] * 100).'% です。'];
        }
        if (($s['storage']['retention_lag_hours'] ?? 0) > 24) {
            $b[] = ['level' => 'warning', 'text' => '正常ログの削除が '.$s['storage']['retention_lag_hours'].' 時間遅延しています。'];
        }
        if ($s['storage']['emergency']) {
            $b[] = ['level' => 'error', 'text' => '緊急ファイル（emergency.log）に保存不能の記録があります。'];
        }

        return $b;
    }

    /** @return array{spool_bytes:int,spool_max_bytes:int,spool_ratio:float,spool_dir:string} */
    public function spoolUsage(): array
    {
        $dir = (string) config('monitoring.collector.spool_dir');
        $max = (int) config('monitoring.collector.spool_max_bytes', 1);
        $bytes = 0;
        if (is_dir($dir)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
                $bytes += $f->getSize();
            }
        }

        return ['spool_bytes' => $bytes, 'spool_max_bytes' => $max, 'spool_ratio' => $max > 0 ? $bytes / $max : 0, 'spool_dir' => $dir];
    }

    public function retentionLagHours(CarbonImmutable $now): int
    {
        $oldest = LogEntry::query()->where('retention_class', 'INFO')->where('expires_at', '<=', $now)->orderBy('expires_at')->first(['expires_at']);

        return $oldest ? (int) CarbonImmutable::instance($oldest->expires_at)->diffInHours($now) : 0;
    }

    public function emergencyLogTail(): ?string
    {
        $path = rtrim((string) config('monitoring.collector.state_dir'), '/').'/emergency.log';
        if (! is_file($path) || filesize($path) === 0) {
            return null;
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        return $lines ? (string) end($lines) : null;
    }
}
