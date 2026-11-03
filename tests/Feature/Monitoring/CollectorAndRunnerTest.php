<?php

namespace Tests\Feature\Monitoring;

use App\Models\Monitoring\Check as CheckModel;
use App\Models\Monitoring\CheckRun;
use App\Models\Monitoring\Collector;
use App\Models\Monitoring\Incident;
use App\Models\Monitoring\LogEntry;
use App\Models\Monitoring\RecheckRequest;
use App\Monitoring\Collectors\FileLogCollector;
use App\Monitoring\Collectors\LaravelLogParser;
use App\Monitoring\Collectors\Shipper;
use App\Monitoring\Collectors\Spool;
use App\Monitoring\Services\CheckRunner;
use App\Monitoring\Services\RecheckService;
use App\Monitoring\Services\SiteRegistry;
use App\Monitoring\Support\Fingerprint;

/** AC-19/20/21/16（収集器）と AC-33/34/35（実行器） */
class CollectorAndRunnerTest extends MonitoringTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/collector-'.uniqid());
        mkdir($this->dir, 0750, true);
        config()->set('monitoring.collector.state_dir', $this->dir.'/state');
        config()->set('monitoring.collector.spool_dir', $this->dir.'/spool');
        config()->set('monitoring.collectors', [[
            'collector_uuid' => '7c7a2f6e-1111-4e8f-9b7e-000000000002', 'application_code' => 'TRADE', 'node_code' => 'web1',
            'secret_env' => 'MONITOR_TEST_SECRET', 'log_files' => [$this->dir.'/laravel.log'], 'parser' => 'laravel',
        ]]);
        config()->set('monitoring.collector.ingest_url', null);
        $this->bootRegistries();
    }

    private function collector(): FileLogCollector
    {
        $cfg = config('monitoring.collectors')[0];

        return new FileLogCollector($cfg, new LaravelLogParser, Spool::forCollector($cfg['collector_uuid']), config('monitoring.collector.state_dir'));
    }

    private function spool(): Spool
    {
        return Spool::forCollector(config('monitoring.collectors')[0]['collector_uuid']);
    }

    public function test_parser_groups_multiline_exceptions_and_carries_partial_tail(): void
    {
        $lines = [
            '[2026-11-02 23:10:00] production.ERROR: Call to undefined method {"exception":"[object] (BadMethodCallException(code: 0): Call to undefined method Foo::bar() at /var/www/app/Services/Foo.php:12)',
            '[stacktrace]',
            '#0 /var/www/app/Http/Controllers/X.php(44): Foo->bar()',
            '#1 {main}',
            '"}',
            '[2026-11-02 23:11:00] production.INFO: [batch] done',
            '[2026-11-02 23:12:00] production.DEBUG: noise',
            '[2026-11-02 23:13:00] production.ERROR: second error begins',
        ];
        $r = (new LaravelLogParser)->parse($lines, flush: false);
        $this->assertCount(3, $r['records']);
        $this->assertSame('BadMethodCallException', $r['records'][0]['error_code']);
        $this->assertStringContainsString('#0 /var/www/app/Http/Controllers/X.php(44)', $r['records'][0]['sample']);
        $this->assertSame('INFO', $r['records'][1]['level']);
        $this->assertNull($r['records'][2]['severity'], 'DEBUG は収集対象外');
        $this->assertCount(1, $r['carry'], '最後の未完了レコードは持ち越す');
    }

    public function test_fingerprint_material_ignores_ids_and_numbers(): void
    {
        $a = Fingerprint::normalizeExceptionMessage('Order 12345 not found for user 9 at 2026-11-02 10:00:00 (req 3f2a-...)');
        $b = Fingerprint::normalizeExceptionMessage('Order 999 not found for user 77 at 2026-11-03 11:30:00 (req 11aa-...)');
        $this->assertSame($a, $b);
    }

    public function test_collector_reads_incrementally_aggregates_and_ships_with_redaction(): void
    {
        $log = $this->dir.'/laravel.log';
        $line = fn ($t, $lvl, $msg) => "[2026-11-02 {$t}] production.{$lvl}: {$msg}\n";
        file_put_contents($log, $line('23:00:01', 'ERROR', 'Deadlock found when trying to get lock; try restarting transaction').$line('23:00:02', 'ERROR', 'Deadlock found when trying to get lock; try restarting transaction').$line('23:00:03', 'INFO', 'ignored info').$line('23:00:04', 'CRITICAL', 'Webhook https://hooks.slack.com/services/T/B/SECRET failed password=xyz'));
        $r = $this->collector()->collect();
        $this->assertSame(2, $r['events'], '同一指紋は60秒窓で集約、INFO は許可パターン外で除外');
        $events = $this->spool()->read($this->spool()->files()[0]);
        $this->assertSame(2, $events[0]['occurrence_delta']);
        $this->assertStringNotContainsString('SECRET', json_encode($events));
        $this->assertStringNotContainsString('xyz', json_encode($events));

        // 送信 → ACK → 退避解放
        $s = app(Shipper::class)->ship(config('monitoring.collectors')[0], $this->spool());
        $this->assertSame(2, $s['accepted']);
        $this->assertCount(0, $this->spool()->files());
        $this->assertSame(2, LogEntry::count());
        $this->assertSame(2, Incident::count());
        $this->assertNotNull(Collector::query()->where('node_code', 'web1')->sole()->last_heartbeat_at);

        // 増分: 追記分だけ読む。途中行は持ち越す
        file_put_contents($log, $line('23:05:00', 'ERROR', 'new failure').'[2026-11-02 23:06:00] production.ERROR: partial', FILE_APPEND);
        $r = $this->collector()->collect();
        $this->assertSame(1, $r['events']);
        file_put_contents($log, " line continues\n".$line('23:07:00', 'ERROR', 'after partial'), FILE_APPEND);
        $r = $this->collector()->collect();
        $this->assertSame(2, $r['events'], '行途中だった header は改行到着後に確定し、後続と合わせて2件');
    }

    public function test_rotation_rename_and_copytruncate_do_not_double_count_or_lose(): void
    {
        $log = $this->dir.'/laravel.log';
        $mk = fn ($n) => "[2026-11-02 23:0{$n}:00] production.ERROR: error number {$n} unique".str_repeat('x', $n)."\n";
        file_put_contents($log, $mk(1).$mk(2));
        $this->assertSame(2, $this->collector()->collect()['events']);
        // rename rotation: 新ファイルは先頭から
        rename($log, $log.'.1');
        file_put_contents($log, $mk(3));
        $this->assertSame(1, $this->collector()->collect()['events']);
        // copytruncate: 同じ inode でサイズが縮む
        file_put_contents($log, '');
        file_put_contents($log, $mk(4).$mk(5));
        $this->assertSame(2, $this->collector()->collect()['events']);
        $this->assertSame(0, $this->collector()->collect()['events'], '再実行で二重計上しない');
        $all = [];
        foreach ($this->spool()->files() as $f) {
            $all = array_merge($all, $this->spool()->read($f));
        }
        $ids = array_column($all, 'event_id');
        $this->assertSame(5, count($ids));
        $this->assertSame(5, count(array_unique($ids)));
    }

    public function test_initial_import_is_capped(): void
    {
        config()->set('monitoring.collector.initial_max_bytes', 200);
        $log = $this->dir.'/laravel.log';
        $content = '';
        for ($i = 0; $i < 50; $i++) {
            $content .= "[2026-11-02 23:00:00] production.ERROR: old error {$i}\n";
        }
        file_put_contents($log, $content);
        $r = $this->collector()->collect();
        $this->assertLessThan(10, $r['events']);
        $this->assertTrue($r['files'][$log]['initial_truncated']);
    }

    public function test_spool_capacity_drops_oldest_info_first_and_keeps_errors(): void
    {
        $spool = new Spool($this->dir.'/sp', 2000, 500);
        $info = fn ($i) => ['event_id' => "i{$i}", 'severity' => 'INFO', 'occurred_at' => '2026-11-02T00:00:00Z', 'summary' => str_repeat('a', 200)];
        $err = ['event_id' => 'e1', 'severity' => 'ERROR', 'occurred_at' => '2026-11-02T00:00:00Z', 'summary' => str_repeat('b', 200)];
        for ($i = 0; $i < 12; $i++) {
            $spool->append([$info($i)]);
        }
        $spool->append([$err]);
        $remaining = [];
        foreach ($spool->files() as $f) {
            $remaining = array_merge($remaining, $spool->read($f));
        }
        $this->assertContains('e1', array_column($remaining, 'event_id'));
        $this->assertLessThan(12, count(array_filter($remaining, fn ($e) => $e['severity'] === 'INFO')));
        $this->assertFileExists($this->dir.'/sp/dropped.log');
    }

    public function test_runner_refuses_non_owner_and_records_unknown_without_source(): void
    {
        config()->set('monitoring.deployment.check_owner_node', 'other-node');
        $stats = app(CheckRunner::class)->run();
        $this->assertStringContainsString('owner', $stats['message']);
        $this->assertSame(0, $stats['executed']);

        config()->set('monitoring.deployment.check_owner_node', config('monitoring.deployment.node_code'));
        $stats = app(CheckRunner::class)->run(null, null, true);
        $this->assertSame(8, $stats['executed'], 'INSIGHTS 自己監視（プローブ・資源を含む）だけ実行');
        $this->assertSame(10, $stats['skipped'], '業務DB未設定のため Trade チェックは実行せず UNKNOWN');
        $this->assertSame('UNKNOWN', CheckModel::query()->where('check_key', 'trade.coupon_daily')->sole()->last_outcome);
        $this->assertTrue(LogEntry::query()->where('error_code', 'MONITOR_SOURCE_NOT_CONFIGURED')->exists());
        $this->assertSame(0, LogEntry::query()->where('check_key', 'like', 'trade.%')->where('outcome', 'HEALTHY')->count(), '未実行を正常にしない');

        // 同じ定期枠の再実行は重複記録しない
        $runs = CheckRun::count();
        app(CheckRunner::class)->run(null, null, true);
        $this->assertSame($runs, CheckRun::count());
    }

    public function test_orphaned_running_run_is_timed_out_on_next_start(): void
    {
        $check = CheckModel::query()->where('check_key', 'monitor.storage')->sole();
        CheckRun::query()->create(['check_id' => $check->id, 'execution_key' => 'orphan', 'trigger_type' => 'SCHEDULE', 'started_at' => now('UTC')->subMinutes(30), 'execution_status' => 'RUNNING']);
        $stats = app(CheckRunner::class)->run(null, ['INSIGHTS:monitor.storage'], true);
        $this->assertSame(1, $stats['timed_out_runs']);
        $this->assertSame('TIMED_OUT', CheckRun::query()->where('execution_key', 'orphan')->sole()->execution_status);
    }

    public function test_recheck_request_is_deduplicated_and_processed(): void
    {
        $check = CheckModel::query()->where('check_key', 'monitor.storage')->sole();
        $svc = app(RecheckService::class);
        $a = $svc->enqueue($check, ['resource' => 'spool'], ['id' => 1, 'name' => 't']);
        $b = $svc->enqueue($check, ['resource' => 'spool'], ['id' => 1, 'name' => 't']);
        $this->assertSame($a->id, $b->id, '対象単位で重複予約をまとめる');
        app(CheckRunner::class)->run(null, [], false);
        $this->assertSame('DONE', RecheckRequest::sole()->status);
        $this->assertSame('HEALTHY', RecheckRequest::sole()->result_outcome);
    }

    public function test_internal_collector_is_deterministic_per_deployment(): void
    {
        $a = app(SiteRegistry::class)->internalCollector('checker');
        $b = app(SiteRegistry::class)->internalCollector('checker');
        $this->assertSame($a->id, $b->id);
    }

    /** 華 本番: 日次ログ（laravel-YYYY-MM-DD.log）をワイルドカードで追い、直近 2 日に更新のあるファイルだけを読む。.env の MONITOR_LOG_COLLECTORS 宣言からも収集器ができる。 */
    public function test_daily_log_glob_and_env_declared_collectors(): void
    {
        $today = now('Asia/Tokyo')->format('Y-m-d');
        $old = $this->dir.'/laravel-2026-01-01.log';
        $new = $this->dir."/laravel-{$today}.log";
        file_put_contents($old, "[2026-01-01 10:00:00] production.ERROR: old error\n");
        touch($old, time() - 10 * 86400);
        file_put_contents($new, "[2026-11-02 23:00:01] production.ERROR: new error\n");
        $this->assertSame([$new], FileLogCollector::expandLogFiles([$this->dir.'/laravel*.log']), '古い日付のファイルは対象外');

        $cfg = config('monitoring.collectors')[0];
        $cfg['log_files'] = [$this->dir.'/laravel*.log'];
        config()->set('monitoring.collectors', [$cfg]);
        $r = $this->collector()->collect();
        $this->assertSame(1, $r['events']);

        // .env 宣言 → config 構築（config ファイルを再評価）
        putenv('MONITOR_LOG_COLLECTORS=WMS=/var/www/hana/sakemaru-wms/storage/logs/laravel*.log;trade=/var/www/hana/sakemaru-trade/storage/logs/laravel.log,/var/www/hana/sakemaru-trade/storage/logs/batch.log');
        putenv('MONITOR_NODE_CODE=hana-prod-1');
        putenv('MONITOR_DEPLOYMENT_ID=hana-prod');
        try {
            $built = (array) (require base_path('config/monitoring.php'))['collectors'];
        } finally {
            putenv('MONITOR_LOG_COLLECTORS');
            putenv('MONITOR_NODE_CODE');
            putenv('MONITOR_DEPLOYMENT_ID');
        }
        $this->assertCount(2, $built);
        $this->assertSame(['WMS', 'TRADE'], array_column($built, 'application_code'));
        $this->assertSame('hana-prod-1', $built[0]['node_code']);
        $this->assertSame('MONITOR_COLLECTOR_SECRET_WMS_HANA_PROD_1', $built[0]['secret_env']);
        $this->assertCount(2, $built[1]['log_files']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $built[0]['collector_uuid']);
        $this->assertNotSame($built[0]['collector_uuid'], $built[1]['collector_uuid']);
    }
}
