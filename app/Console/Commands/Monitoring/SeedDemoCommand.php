<?php

namespace App\Console\Commands\Monitoring;

use App\Models\Monitoring\Incident;
use App\Models\Monitoring\Notification;
use App\Monitoring\Enums\NotificationStatus;
use App\Monitoring\Services\CheckRegistry;
use App\Monitoring\Services\IncidentService;
use App\Monitoring\Services\ObservationIngestor;
use App\Monitoring\Services\RunbookRegistry;
use App\Monitoring\Services\SiteRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * ローカル／STG の画面確認用サンプルデータ。本番では実行できない。
 * 取込器を通して生成するため、実際の経路（指紋・インシデント・通知待ち）と同じ形になる。
 */
class SeedDemoCommand extends Command
{
    protected $signature = 'monitor:seed-demo {--hours=36 : 生成する時間幅}';

    protected $description = '運用監視: 画面確認用のサンプル監視データを生成する（非本番のみ）';

    public function handle(SiteRegistry $sites, CheckRegistry $checks, RunbookRegistry $runbooks, ObservationIngestor $ingestor, IncidentService $incidents): int
    {
        if ($sites->isProduction()) {
            $this->error('本番環境では実行できません。');

            return self::FAILURE;
        }
        $sites->sync();
        $checks->sync($sites);
        $runbooks->sync();
        $now = CarbonImmutable::now('UTC');
        $hours = (int) $this->option('hours');
        $events = [];
        $seq = 1;

        $mk = function (array $o) use (&$seq, $now, $hours) {
            $at = $o['at'] ?? $now->subMinutes(random_int(0, $hours * 60));

            return array_merge([
                'schema_version' => 1,
                'event_id' => (string) Str::uuid(),
                'node_code' => 'web1',
                'source_kind' => 'CHECK',
                'severity' => 'INFO',
                'outcome' => 'HEALTHY',
                'run_id' => $seq++,
                'occurred_at' => $at->toIso8601String(),
                'verification_complete' => true,
            ], $o);
        };

        // 定期チェックの正常ログ（5分ごと想定の一部）
        $yesterday = $now->setTimezone('Asia/Tokyo')->subDay()->toDateString();
        for ($i = 0; $i < $hours * 4; $i++) {
            $at = $now->subMinutes($i * 15);
            $events[] = $mk(['application_code' => 'TRADE', 'check_key' => 'trade.transfer_pending', 'scope' => ['kind' => 'store_sync'], 'summary' => 'store_sync: 滞留なし。', 'at' => $at]);
            $events[] = $mk(['application_code' => 'TRADE', 'check_key' => 'trade.hourly_receive', 'scope' => ['window' => 'recent'], 'summary' => '直近3時間に締切を迎えた時刻指定受信はありません。', 'at' => $at]);
            if ($i % 4 === 0) {
                $events[] = $mk(['application_code' => 'INSIGHTS', 'check_key' => 'monitor.storage', 'scope' => ['resource' => 'spool'], 'summary' => '退避領域の使用率は 3.1% です。', 'metrics' => ['spool_bytes' => 6_500_000], 'at' => $at]);
            }
        }
        // ワーニング: 専用受信欠番（他経路あり）
        $events[] = $mk(['application_code' => 'TRADE', 'check_key' => 'trade.hourly_receive', 'severity' => 'WARNING', 'outcome' => 'WARNING', 'error_code' => 'DEDICATED_RECEIVE_MISSING', 'business_date' => $now->setTimezone('Asia/Tokyo')->toDateString(), 'scope' => ['business_date' => $now->setTimezone('Asia/Tokyo')->toDateString(), 'target_hour' => 13], 'summary' => '時刻指定受信 13時 が未完了（FAILED）。5分受信は同時間帯に 12 回成功しており、実データ欠落とは断定しません。', 'evidence' => ['request_id' => 48213, 'other_route_success' => 12], 'at' => $now->subHours(2)]);
        // エラー: クーポン統計欠落（小浜店回帰例）
        $events[] = $mk(['application_code' => 'TRADE', 'check_key' => 'trade.coupon_daily', 'severity' => 'ERROR', 'outcome' => 'VIOLATION', 'error_code' => 'COUPON_STATS_MISMATCH', 'business_date' => $yesterday, 'scope' => ['business_date' => $yesterday],
            'summary' => 'クーポン日別統計が元POSと不一致: 期待 107組 / 保存 0組 / 欠落 107 / 不一致 0（11店舗）', 'title' => 'クーポン日別統計が欠落しています',
            'metrics' => ['expected_groups' => 107, 'stored_groups' => 0, 'missing' => 107, 'mismatch' => 0], 'evidence' => ['missing' => [['key' => '22|9800010140006', 'pos_txn' => 2]], 'stores' => ['1', '3', '5', '7', '9', '11', '13', '15', '17', '19', '22']], 'runbook_key' => 'RB-01', 'at' => $now->subMinutes(70)]);
        // エラー: 会員統計欠落（別日）
        $events[] = $mk(['application_code' => 'TRADE', 'check_key' => 'trade.member_daily', 'severity' => 'ERROR', 'outcome' => 'VIOLATION', 'error_code' => 'MEMBER_STATS_MISSING', 'business_date' => $yesterday, 'scope' => ['business_date' => $yesterday], 'summary' => "会員日別統計が欠落: 対象営業日 {$yesterday} / 欠落 11 店舗（期待 21）", 'metrics' => ['expected_stores' => 21, 'stored_rows' => 10, 'missing' => 11], 'runbook_key' => 'RB-02', 'at' => $now->subMinutes(55)]);
        // アプリエラー（WMS）: 同一指紋の大量発生
        foreach ([180, 120, 60, 12] as $m) {
            $events[] = $mk(['application_code' => 'WMS', 'node_code' => 'web2', 'source_kind' => 'APPLICATION_LOG', 'severity' => 'ERROR', 'outcome' => null, 'run_id' => null, 'error_code' => 'QueryException', 'scope' => ['fp' => 'a91f0c3e2b77d10c', 'file' => 'laravel.log'],
                'summary' => 'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock', 'title' => 'QueryException: SQLSTATE[40001]: Serialization failure: 1213 Deadlock found', 'occurrence_delta' => random_int(3, 40),
                'evidence' => ['sample' => "[datetime] production.ERROR: SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock\n#0 app/Services/StockService.php(212): Illuminate\\Database\\Connection->runQueryCallback()\n#1 app/Jobs/ApplyStockMovement.php(44): App\\Services\\StockService->apply()", 'exception' => ['class' => 'Illuminate\\Database\\QueryException', 'file' => 'app/Services/StockService.php']], 'runbook_key' => 'RB-07', 'at' => $now->subMinutes($m)]);
        }
        // CRITICAL（DELIVERY）
        $events[] = $mk(['application_code' => 'DELIVERY', 'node_code' => 'web1', 'source_kind' => 'APPLICATION_LOG', 'severity' => 'CRITICAL', 'outcome' => null, 'run_id' => null, 'error_code' => 'RuntimeException', 'scope' => ['fp' => '0c22ab9ee1f2', 'file' => 'laravel.log'], 'summary' => '配送ラベルの印刷サービスに接続できません (connection refused)', 'title' => 'RuntimeException: 配送ラベルの印刷サービスに接続できません', 'evidence' => ['sample' => "production.CRITICAL: RuntimeException: 配送ラベルの印刷サービスに接続できません\n#0 app/Printing/LabelClient.php(88)"], 'runbook_key' => 'RB-07', 'at' => $now->subMinutes(8)]);
        // 監視システム: 通知経路
        $events[] = $mk(['application_code' => 'INSIGHTS', 'source_kind' => 'MONITOR_SYSTEM', 'severity' => 'WARNING', 'outcome' => null, 'run_id' => null, 'error_code' => 'MONITOR_SOURCE_NOT_CONFIGURED', 'scope' => ['resource' => 'monitor_source'], 'summary' => '業務DB接続（monitor_source）が未設定のため、業務照合チェックは実行していません。', 'at' => $now->subMinutes(3)]);

        usort($events, fn ($a, $b) => strcmp($a['occurred_at'], $b['occurred_at']));
        $accepted = 0;
        foreach (array_chunk($events, (int) config('monitoring.ingest.max_batch_events', 100)) as $chunk) {
            $r = $ingestor->ingestInternal($chunk, 'demo');
            $accepted += count($r['accepted']);
            foreach ($r['rejected'] as $rej) {
                $this->warn(json_encode($rej, JSON_UNESCAPED_UNICODE));
            }
        }
        $this->line("events accepted={$accepted}");

        // 対応状態のバリエーション
        $actor = ['id' => null, 'name' => '運用 太郎'];
        $member = Incident::query()->where('check_key', 'trade.member_daily')->first();
        if ($member) {
            $member = $incidents->act($member, 'acknowledge', $member->version, $actor, ['reason' => '受信ログを確認中'], 'demo:ack:'.$member->id);
            $incidents->act($member, 'start', $member->version, $actor, ['reason' => '集計ジョブの実行履歴を確認。排他ロック競合の可能性。'], 'demo:start:'.$member->id);
        }
        $wms = Incident::query()->where('error_code', 'QueryException')->first();
        if ($wms) {
            $wms = $incidents->act($wms, 'acknowledge', $wms->version, ['id' => null, 'name' => '倉庫 花子'], [], 'demo:ack:'.$wms->id);
            $incidents->act($wms, 'snooze', $wms->version, ['id' => null, 'name' => '倉庫 花子'], ['hours' => 2, 'reason' => '在庫移動ジョブの再実行中。完了まで通知を止める。'], 'demo:snooze:'.$wms->id);
        }
        // 通知の配送状態を模擬
        foreach (Notification::query()->get() as $i => $n) {
            if ($i % 3 === 2) {
                continue;
            }
            $n->status = NotificationStatus::DELIVERED;
            $n->delivered_at = $now->subMinutes(1);
            $n->attempt_count = 1;
            $n->last_http_status = 200;
            $n->save();
            if ($n->incident_id) {
                Incident::query()->where('id', $n->incident_id)->update(['last_delivery_status' => 'DELIVERED', 'last_delivered_at' => $n->delivered_at]);
            }
        }
        $this->info('demo data ready: incidents='.Incident::count().' logs='.\App\Models\Monitoring\LogEntry::count());

        return self::SUCCESS;
    }
}
