<?php

namespace App\Monitoring\Services;

use App\Monitoring\Enums\Severity;
use App\Monitoring\Enums\SourceKind;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Insights 自身の異常（通知失敗・保存不能・予算超過など）を INSIGHTS サイトのイベントとして記録する。
 * 監視の異常を監視から隠さない（仕様書 3.1-5 / 15.3）。
 */
class SelfMonitor
{
    public function __construct(private readonly ObservationIngestor $ingestor, private readonly SiteRegistry $sites) {}

    public function record(string $errorCode, Severity $severity, string $summary, array $scope = [], array $evidence = [], array $metrics = [], ?string $runbookKey = null, bool $nodeScoped = false): void
    {
        try {
            $this->ingestor->ingestInternal([[
                'schema_version' => 1,
                'event_id' => (string) Str::uuid(),
                'application_code' => 'INSIGHTS',
                'node_code' => $this->sites->nodeCode(),
                'source_kind' => SourceKind::MONITOR_SYSTEM->value,
                'severity' => $severity->value,
                'error_code' => $errorCode,
                'occurred_at' => CarbonImmutable::now('UTC')->toIso8601String(),
                'summary' => $summary,
                'scope' => $scope,
                'evidence' => $evidence,
                'metrics' => $metrics,
                'runbook_key' => $runbookKey,
                'node_scoped' => $nodeScoped,
            ]], 'self');
        } catch (\Throwable $e) {
            // 保存不能時は stderr と緊急ファイルへ（仕様書 15.3）
            $this->emergency($errorCode, $summary, $e);
        }
    }

    /** 原因が解消した監視システム案件を復旧にする（休止の解除・配送の回復など） */
    public function recovered(string $errorCode, array $scope, string $reason, bool $nodeScoped = false): void
    {
        try {
            app(IncidentService::class)->recordSelfRecovered($errorCode, $scope, $reason, $nodeScoped ? $this->sites->nodeCode() : null);
        } catch (\Throwable $e) {
            $this->emergency($errorCode, '復旧の記録に失敗: '.$reason, $e);
        }
    }

    public function notificationBlocked(string $routeKey, string $reason): void
    {
        $label = match ($routeKey) {
            'mail' => 'メール通知',
            'phone' => '電話通知',
            default => "Slack通知経路 {$routeKey}",
        };
        $this->record('MONITOR_NOTIFICATION_BLOCKED', Severity::ERROR, "{$label} が恒久的に失敗しています（{$reason}）。設定を確認し、代替連絡を使用してください。", ['route' => $routeKey], ['reason' => $reason], [], 'RB-09');
    }

    public function emergency(string $code, string $summary, ?\Throwable $e = null): void
    {
        $line = sprintf("[%s] MONITOR_EMERGENCY %s %s %s\n", CarbonImmutable::now('UTC')->toIso8601String(), $code, $summary, $e ? get_class($e).': '.mb_substr($e->getMessage(), 0, 200) : '');
        @file_put_contents('php://stderr', $line);
        $dir = config('monitoring.collector.state_dir');
        if ($dir) {
            @mkdir($dir, 0750, true);
            @file_put_contents(rtrim($dir, '/').'/emergency.log', $line, FILE_APPEND | LOCK_EX);
        }
    }
}
