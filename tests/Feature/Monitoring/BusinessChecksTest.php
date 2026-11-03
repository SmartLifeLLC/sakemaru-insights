<?php

namespace Tests\Feature\Monitoring;

use App\Monitoring\Checks\Core;
use App\Monitoring\Checks\Delivery;
use App\Monitoring\Checks\Search;
use App\Monitoring\Checks\Wms;
use App\Monitoring\Contracts\Check;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\CheckResult;
use App\Monitoring\Services\SourceReader;
use Carbon\CarbonImmutable;

/**
 * 仕様書 v2 V-030〜V-048: 新規業務チェックの判定。業務テーブルは SQLite のスタブ（本番の列名に合わせる）。
 * 時刻は JST で与える（業務DBはアプリ時刻 Asia/Tokyo で保存されている）。
 */
class BusinessChecksTest extends MonitoringTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootRegistries();
        $this->useSourceStub();
    }

    private function exec(Check $check, string $nowJst, array $config = []): CheckResult
    {
        $now = CarbonImmutable::parse($nowJst, 'Asia/Tokyo')->utc();
        $cfg = array_merge($check->definition()->defaultConfig, $config);

        return $check->evaluate(new CheckContext(SourceReader::fromConfig(), $now, $cfg));
    }

    /** @return array<string,string> "scope json" => outcome */
    private function outcomes(CheckResult $r): array
    {
        $out = [];
        foreach ($r->observations as $o) {
            $scope = $o->scope;
            ksort($scope);
            $out[json_encode($scope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)] = $o->outcome->value;
        }

        return $out;
    }

    private function only(CheckResult $r): string
    {
        $this->assertCount(1, $r->observations, json_encode(array_map(fn ($o) => $o->summary, $r->observations), JSON_UNESCAPED_UNICODE));

        return $r->observations[0]->outcome->value;
    }

    // ------------------------------------------------------------------ 蔵

    private function wmsAutoOrderTables(): void
    {
        $this->sourceTable('wms_warehouse_auto_order_settings', fn ($t) => [$t->id(), $t->unsignedBigInteger('warehouse_id'), $t->boolean('is_auto_order_enabled')]);
        $this->sourceTable('wms_auto_order_runs', fn ($t) => [$t->id(), $t->string('slot_key'), $t->boolean('is_preview')->default(0), $t->string('status'), $t->unsignedBigInteger('warehouse_id'), $t->integer('failed_count')->default(0), $t->integer('generated_count')->default(0), $t->integer('held_count')->default(0)]);
    }

    public function test_wms_auto_order_run(): void
    {
        $this->wmsAutoOrderTables();
        $c = new Wms\AutoOrderRunCheck;
        $this->assertSame('HEALTHY', $this->only($this->exec($c, '2026-11-03 09:00')), '有効倉庫なし → 対象なし');

        $this->sourceInsert('wms_warehouse_auto_order_settings', [['warehouse_id' => 1, 'is_auto_order_enabled' => 1], ['warehouse_id' => 2, 'is_auto_order_enabled' => 1]]);
        $o = $this->outcomes($this->exec($c, '2026-11-03 02:00'));
        $this->assertArrayNotHasKey('{"business_date":"2026-11-03","slot":"01:00"}', $o, '01:00＋90 分の前は当日枠を判定しない');
        $this->assertSame('VIOLATION', $o['{"business_date":"2026-11-02","slot":"01:00"}'], '26 時間以内の前日枠は再判定');
        $first = fn ($at) => $this->outcomes($this->exec($c, $at))['{"business_date":"2026-11-03","slot":"01:00"}'];
        $this->assertSame('VIOLATION', $first('2026-11-03 02:31'), '未完了');

        $this->sourceInsert('wms_auto_order_runs', ['slot_key' => '2026-11-03:01:00', 'is_preview' => 1, 'status' => 'COMPLETED', 'warehouse_id' => 1]);
        $this->assertSame('VIOLATION', $first('2026-11-03 02:31'), 'preview は除外');

        $this->sourceInsert('wms_auto_order_runs', ['slot_key' => '2026-11-03:01:00', 'status' => 'COMPLETED', 'warehouse_id' => 1, 'generated_count' => 30]);
        $this->assertSame('WARNING', $first('2026-11-03 02:31'), '一部倉庫のみ完了');
        $this->sourceInsert('wms_auto_order_runs', ['slot_key' => '2026-11-03:01:00', 'status' => 'COMPLETED', 'warehouse_id' => 2, 'generated_count' => 5]);
        $this->assertSame('HEALTHY', $first('2026-11-03 02:31'));

        \DB::connection('monitor_source')->table('wms_auto_order_runs')->where('warehouse_id', 2)->update(['failed_count' => 3]);
        $this->assertSame('WARNING', $first('2026-11-03 02:31'), 'failed_count > 0');
    }

    public function test_wms_auto_order_calc(): void
    {
        $this->sourceTable('wms_auto_order_job_controls', fn ($t) => [$t->id(), $t->string('process_name'), $t->string('status'), $t->unsignedBigInteger('warehouse_id')->nullable(), $t->date('target_date')->nullable(), $t->dateTime('started_at')->nullable(), $t->dateTime('finished_at')->nullable(), $t->dateTime('created_at'), $t->text('error_details')->nullable()]);
        $c = new Wms\AutoOrderCalcCheck;
        $this->sourceInsert('wms_auto_order_job_controls', [
            ['process_name' => 'ORDER_CALC', 'status' => 'SUCCESS', 'created_at' => '2026-11-03 09:00:00', 'started_at' => '2026-11-03 09:00:00'],
            ['process_name' => 'ORDER_CALC', 'status' => 'FAILED', 'created_at' => '2026-11-03 10:00:00', 'started_at' => '2026-11-03 10:00:00', 'error_details' => 'SQLSTATE password=secret'],
            ['process_name' => 'SALES_BASED_CALC', 'status' => 'RUNNING', 'created_at' => '2026-11-03 08:00:00', 'started_at' => '2026-11-03 08:00:00'],
            ['process_name' => 'STOCK_SNAPSHOT', 'status' => 'PENDING', 'created_at' => '2026-11-03 10:20:00'],
        ]);
        $o = $this->outcomes($this->exec($c, '2026-11-03 11:00'));
        $this->assertSame('VIOLATION', $o['{"process":"ORDER_CALC"}'], '最新が FAILED');
        $this->assertSame('VIOLATION', $o['{"process":"SALES_BASED_CALC"}'], 'RUNNING 60 分超');
        $this->assertSame('WARNING', $o['{"process":"STOCK_SNAPSHOT"}'], 'PENDING 30 分超');
        $this->sourceInsert('wms_auto_order_job_controls', ['process_name' => 'ORDER_CALC', 'status' => 'SUCCESS', 'created_at' => '2026-11-03 10:30:00']);
        $this->assertSame('HEALTHY', $this->outcomes($this->exec($c, '2026-11-03 11:00'))['{"process":"ORDER_CALC"}'], '後続成功で復旧');
    }

    public function test_wms_jx_order_generation_by_weekday(): void
    {
        $this->sourceTable('wms_jx_order_generation_runs', fn ($t) => [$t->id(), $t->date('target_date'), $t->string('generation_time'), $t->string('status'), $t->integer('generated_document_count')->default(0), $t->text('error_message')->nullable()]);
        $c = new Wms\JxOrderGenerationCheck;
        // 2026-11-03 は火曜。14:15 時点で 火 13:30 と 月 13:30（26 時間以内）の 2 枠が判定対象
        $this->sourceInsert('wms_jx_order_generation_runs', ['target_date' => '2026-11-02', 'generation_time' => '13:30', 'status' => 'SUCCESS', 'generated_document_count' => 3]);
        $o = $this->outcomes($this->exec($c, '2026-11-03 14:15'));
        $this->assertSame('HEALTHY', $o['{"business_date":"2026-11-02","slot":"13:30"}']);
        $this->assertSame('VIOLATION', $o['{"business_date":"2026-11-03","slot":"13:30"}'], '予定＋30 分で未完了');
        $this->assertArrayNotHasKey('{"business_date":"2026-11-02","slot":"13:30"}', $this->outcomes($this->exec($c, '2026-11-02 12:00')), '予定前は判定しない');

        $this->sourceInsert('wms_jx_order_generation_runs', ['target_date' => '2026-11-03', 'generation_time' => '13:30', 'status' => 'FAILED', 'error_message' => 'timeout']);
        $this->assertSame('VIOLATION', $this->outcomes($this->exec($c, '2026-11-03 14:15'))['{"business_date":"2026-11-03","slot":"13:30"}'], '失敗');
        // 日曜は 23:35
        $this->sourceInsert('wms_jx_order_generation_runs', ['target_date' => '2026-11-01', 'generation_time' => '23:35', 'status' => 'SUCCESS']);
        $this->assertSame('HEALTHY', $this->outcomes($this->exec($c, '2026-11-02 00:10'))['{"business_date":"2026-11-01","slot":"23:35"}']);
    }

    public function test_wms_jx_transmission(): void
    {
        $this->sourceTable('wms_jx_transmission_logs', fn ($t) => [$t->id(), $t->string('direction')->nullable(), $t->string('operation_type')->nullable(), $t->string('document_type')->nullable(), $t->string('status'), $t->integer('http_code')->nullable(), $t->text('error_message')->nullable(), $t->dateTime('created_at')]);
        $this->sourceTable('wms_order_jx_settings', fn ($t) => [$t->id(), $t->boolean('is_active')]);
        $c = new Wms\JxTransmissionCheck;
        $this->sourceInsert('wms_jx_transmission_logs', [
            ['direction' => 'send', 'operation_type' => 'PutDocument', 'document_type' => '91', 'status' => 'success', 'created_at' => '2026-11-03 09:00:00'],
            ['direction' => 'send', 'operation_type' => 'PutDocument', 'document_type' => '91', 'status' => 'failure', 'http_code' => 500, 'created_at' => '2026-11-03 10:00:00'],
            ['direction' => 'receive', 'operation_type' => 'GetDocument', 'document_type' => '90', 'status' => 'success', 'created_at' => '2026-11-03 10:00:00'],
        ]);
        $o = $this->outcomes($this->exec($c, '2026-11-03 11:00'));
        $this->assertSame('VIOLATION', $o['{"transmission":"send/PutDocument/91"}']);
        $this->assertSame('HEALTHY', $o['{"transmission":"receive/GetDocument/90"}']);
        $this->assertArrayNotHasKey('{"transmission":"silence"}', $o, '有効な JX 設定が無ければ無送信は判定しない');

        $this->sourceInsert('wms_jx_transmission_logs', ['direction' => 'send', 'operation_type' => 'PutDocument', 'document_type' => '91', 'status' => 'success', 'created_at' => '2026-11-03 10:30:00']);
        $o = $this->outcomes($this->exec($c, '2026-11-03 11:00'));
        $this->assertSame('HEALTHY', $o['{"transmission":"send/PutDocument/91"}'], '後続成功で復旧（指紋に日付なし）');
        $this->assertSame('WARNING', $o['{"state":"recent_failures","transmission":"send/PutDocument/91"}']);

        $this->sourceInsert('wms_order_jx_settings', ['is_active' => 1]);
        $this->assertSame('WARNING', $this->outcomes($this->exec($c, '2026-11-05 12:00'))['{"transmission":"silence"}'], '有効設定あり・48 時間無送信');
    }

    public function test_wms_order_transmission(): void
    {
        $this->sourceTable('wms_order_jx_documents', fn ($t) => [$t->id(), $t->string('status'), $t->string('error_message')->nullable(), $t->dateTime('updated_at'), $t->dateTime('transmitted_at')->nullable(), $t->dateTime('confirmed_at')->nullable()]);
        $this->sourceTable('wms_order_transmission_logs', fn ($t) => [$t->id(), $t->string('transmission_type'), $t->string('status'), $t->dateTime('created_at'), $t->string('error_code')->nullable(), $t->string('error_message')->nullable()]);
        $c = new Wms\OrderTransmissionCheck;
        $this->sourceInsert('wms_order_jx_documents', ['status' => 'TRANSMITTED', 'updated_at' => '2026-11-03 09:00:00', 'transmitted_at' => '2026-11-01 09:00:00']);
        $this->sourceInsert('wms_order_transmission_logs', [
            ['transmission_type' => 'JX_FINET', 'status' => 'FAILED', 'created_at' => '2026-11-03 09:00:00'],
            ['transmission_type' => 'MANUAL_CSV', 'status' => 'FAILED', 'created_at' => '2026-11-03 09:00:00'],
            ['transmission_type' => 'FTP', 'status' => 'PENDING', 'created_at' => '2026-11-03 08:00:00'],
        ]);
        $o = $this->outcomes($this->exec($c, '2026-11-03 10:00'));
        $this->assertSame('HEALTHY', $o['{"documents":"error"}']);
        $this->assertSame('VIOLATION', $o['{"transmission_type":"JX_FINET"}']);
        $this->assertSame('WARNING', $o['{"transmission_type":"MANUAL_CSV"}'], '手動 CSV は WARNING');
        $this->assertSame('WARNING', $o['{"transmission_type":"FTP"}'], 'PENDING 60 分超');
        $this->assertArrayNotHasKey('{"documents":"unconfirmed"}', $o, '未確認警告は既定 OFF');
        $o = $this->outcomes($this->exec($c, '2026-11-03 10:00', ['unconfirmed_warning' => true]));
        $this->assertSame('WARNING', $o['{"documents":"unconfirmed"}']);
        $this->sourceInsert('wms_order_jx_documents', ['status' => 'ERROR', 'error_message' => 'JX 応答エラー', 'updated_at' => '2026-11-03 09:30:00']);
        $this->assertSame('VIOLATION', $this->outcomes($this->exec($c, '2026-11-03 10:00'))['{"documents":"error"}']);
    }

    public function test_wms_eos_incoming_receive_uses_business_schedule(): void
    {
        $this->sourceTable('wms_eos_incoming_receive_schedules', fn ($t) => [$t->id(), $t->string('schedule_type'), $t->unsignedTinyInteger('day_of_week')->nullable(), $t->time('receive_time'), $t->boolean('is_enabled')]);
        $this->sourceTable('wms_eos_incoming_receive_runs', fn ($t) => [$t->id(), $t->date('execution_date'), $t->time('scheduled_time')->nullable(), $t->string('status'), $t->integer('error_count')->default(0), $t->text('error_summary')->nullable()]);
        $c = new Wms\EosIncomingReceiveCheck;
        $this->assertSame('HEALTHY', $this->only($this->exec($c, '2026-11-03 10:00')), 'スケジュールなし → 対象なし');
        $this->sourceInsert('wms_eos_incoming_receive_schedules', [
            ['schedule_type' => 'DAILY', 'day_of_week' => 0, 'receive_time' => '22:00:00', 'is_enabled' => 1],
            ['schedule_type' => 'WEEKLY_EXTRA', 'day_of_week' => 1, 'receive_time' => '10:00:00', 'is_enabled' => 1],
            ['schedule_type' => 'WEEKLY_EXTRA', 'day_of_week' => 2, 'receive_time' => '10:00:00', 'is_enabled' => 0],
        ]);
        // 月曜 2026-11-02 11:00: 月 10:00 と 日 22:00 が判定対象、無効な火曜枠は対象外
        $this->sourceInsert('wms_eos_incoming_receive_runs', ['execution_date' => '2026-11-01', 'scheduled_time' => '22:00:00', 'status' => 'SUCCEEDED']);
        $this->sourceInsert('wms_eos_incoming_receive_runs', ['execution_date' => '2026-11-02', 'scheduled_time' => '10:00:00', 'status' => 'PARTIAL_FAILED', 'error_count' => 2]);
        $o = $this->outcomes($this->exec($c, '2026-11-02 11:00'));
        $this->assertSame('HEALTHY', $o['{"business_date":"2026-11-01","slot":"22:00"}']);
        $this->assertSame('WARNING', $o['{"business_date":"2026-11-02","slot":"10:00"}'], '一部失敗');
        $o = $this->outcomes($this->exec($c, '2026-11-03 11:00'));
        $this->assertArrayNotHasKey('{"business_date":"2026-11-03","slot":"10:00"}', $o, '無効スケジュールは判定しない');
        $this->assertSame('VIOLATION', $o['{"business_date":"2026-11-02","slot":"22:00"}'], '実行記録なし');
    }

    public function test_wms_incoming_import_errors_surge_uses_median(): void
    {
        $this->sourceTable('wms_incoming_import_errors', fn ($t) => [$t->id(), $t->string('error_type')->nullable(), $t->dateTime('created_at')]);
        $rows = [];
        foreach (['2026-10-26' => 600, '2026-10-27' => 700, '2026-10-28' => 650, '2026-10-29' => 800, '2026-10-30' => 900, '2026-10-31' => 100, '2026-11-01' => 50] as $d => $n) {
            for ($i = 0; $i < $n; $i += 50) {
                $rows[] = ['error_type' => 'PRICE', 'created_at' => $d.' 10:00:00'];
            }
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            $this->sourceInsert('wms_incoming_import_errors', $chunk);
        }
        $c = new Wms\IncomingImportErrorsCheck;
        $this->assertSame('HEALTHY', $this->only($this->exec($c, '2026-11-03 09:00', ['min_count' => 5])), '前日 0 件');
        for ($i = 0; $i < 40; $i++) {
            $this->sourceInsert('wms_incoming_import_errors', ['error_type' => 'UNKNOWN_ITEM', 'created_at' => '2026-11-02 12:00:00']);
        }
        // 中央値 = 13（50 件単位の行数を 1 件で代用）→ 前日 40 は 2 倍超・下限 5 以上
        $this->assertSame('WARNING', $this->only($this->exec($c, '2026-11-03 09:00', ['min_count' => 5])));
        $this->assertSame('HEALTHY', $this->only($this->exec($c, '2026-11-03 09:00', ['min_count' => 1000])), '下限件数未満は警告しない');
    }

    public function test_queue_failed_counts_only_new_failures_by_exception(): void
    {
        $this->sourceTable('wms_failed_jobs', fn ($t) => [$t->id(), $t->string('queue')->nullable(), $t->longText('exception'), $t->dateTime('failed_at')]);
        $c = new Wms\QueueFailed;
        $this->sourceInsert('wms_failed_jobs', [
            ['queue' => 'default', 'exception' => "Illuminate\\Database\\QueryException: SQLSTATE[40001] Deadlock 123 in /app/x.php:12\n#0 ...", 'failed_at' => '2026-11-03 09:00:00'],
            ['queue' => 'default', 'exception' => "Illuminate\\Database\\QueryException: SQLSTATE[40001] Deadlock 456 in /app/x.php:12\n#0 ...", 'failed_at' => '2026-11-03 09:05:00'],
            ['queue' => 'mail', 'exception' => "RuntimeException: SMTP down\n#0", 'failed_at' => '2026-11-03 09:10:00'],
        ]);
        $r = $this->exec($c, '2026-11-03 10:00');
        $this->assertCount(2, $r->observations, '例外種別ごとに集約');
        $this->assertSame(3, $r->checkpoint['last_id']);
        // 次回は checkpoint 以降だけ
        $now = CarbonImmutable::parse('2026-11-03 10:10', 'Asia/Tokyo')->utc();
        $r2 = $c->evaluate(new CheckContext(SourceReader::fromConfig(), $now, $c->definition()->defaultConfig, [], 'SCHEDULE', ['last_id' => 3]));
        $this->assertSame('HEALTHY', $this->only($r2));
    }

    // ------------------------------------------------------------------ 勘定衆

    private function statsTable(): void
    {
        $this->sourceTable('stats_import_runs', fn ($t) => [$t->id(), $t->string('job_type'), $t->string('source_system'), $t->string('status'), $t->dateTime('started_at'), $t->text('error_message')->nullable(), $t->dateTime('created_at')->nullable()]);
    }

    public function test_core_stats_daily_aggregate_slots(): void
    {
        $this->statsTable();
        $c = new Core\StatsDailyAggregateCheck;
        $cfg = ['slots' => ['02:00', '07:00']];
        $this->sourceInsert('stats_import_runs', [
            ['job_type' => 'daily_aggregate', 'source_system' => 'core_earning', 'status' => 'succeeded', 'started_at' => '2026-11-03 02:01:00'],
            ['job_type' => 'daily_aggregate', 'source_system' => 'core_earning', 'status' => 'succeeded', 'started_at' => '2026-11-03 07:00:20'],
        ]);
        $o = $this->outcomes($this->exec($c, '2026-11-03 07:40', $cfg));
        $this->assertSame('HEALTHY', $o['{"business_date":"2026-11-03","slot":"02:00"}'], '02:00 は部門同期不要');
        $this->assertSame('VIOLATION', $o['{"business_date":"2026-11-03","slot":"07:00"}'], '07:00 は部門同期も必要');
        $this->sourceInsert('stats_import_runs', ['job_type' => 'daily_department_sync', 'source_system' => 'core_earning', 'status' => 'succeeded', 'started_at' => '2026-11-03 07:00:21']);
        $this->assertSame('HEALTHY', $this->outcomes($this->exec($c, '2026-11-03 07:40', $cfg))['{"business_date":"2026-11-03","slot":"07:00"}']);
        $this->sourceInsert('stats_import_runs', ['job_type' => 'daily_aggregate', 'source_system' => 'core_earning', 'status' => 'failed', 'started_at' => '2026-11-03 07:05:00', 'error_message' => 'boom']);
        $this->assertSame('VIOLATION', $this->outcomes($this->exec($c, '2026-11-03 07:40', $cfg))['{"business_date":"2026-11-03","slot":"07:00"}'], 'failed');
        $this->assertArrayNotHasKey('{"business_date":"2026-11-03","slot":"07:00"}', $this->outcomes($this->exec($c, '2026-11-03 01:00', $cfg)));
    }

    public function test_core_stats_daily_finalize(): void
    {
        $this->statsTable();
        $c = new Core\StatsDailyFinalizeCheck;
        $this->assertArrayNotHasKey('{"business_date":"2026-11-02"}', $this->outcomes($this->exec($c, '2026-11-03 02:30')), '判定時刻前');
        $this->assertSame('VIOLATION', $this->outcomes($this->exec($c, '2026-11-03 02:50'))['{"business_date":"2026-11-02"}']);
        $this->sourceInsert('stats_import_runs', [
            ['job_type' => 'daily_finalize', 'source_system' => 'core_earning', 'status' => 'succeeded', 'started_at' => '2026-11-03 02:05:00'],
            ['job_type' => 'daily_department_sync', 'source_system' => 'mixed', 'status' => 'succeeded', 'started_at' => '2026-11-03 02:20:00'],
        ]);
        $this->assertSame('HEALTHY', $this->outcomes($this->exec($c, '2026-11-03 02:50'))['{"business_date":"2026-11-02"}'], '対象は前営業日');
    }

    public function test_core_sales_performance_and_closing(): void
    {
        $this->sourceTable('stats_sales_performance_runs', fn ($t) => [$t->id(), $t->string('name')->nullable(), $t->string('status'), $t->text('error_message')->nullable(), $t->dateTime('completed_at')->nullable(), $t->string('progress_stage')->nullable(), $t->dateTime('progress_updated_at')->nullable(), $t->dateTime('started_at')->nullable(), $t->dateTime('created_at'), $t->dateTime('updated_at')]);
        $this->sourceInsert('stats_sales_performance_runs', [
            ['name' => '月次', 'status' => 'failed', 'error_message' => 'x', 'created_at' => '2026-11-03 09:00:00', 'updated_at' => '2026-11-03 09:10:00'],
            ['name' => '日次', 'status' => 'running', 'progress_updated_at' => '2026-11-03 09:00:00', 'created_at' => '2026-11-03 08:59:00', 'updated_at' => '2026-11-03 09:00:00'],
            ['name' => '古い', 'status' => 'expired', 'created_at' => '2026-11-03 08:00:00', 'updated_at' => '2026-11-03 08:00:00'],
        ]);
        $o = $this->outcomes($this->exec(new Core\SalesPerformanceCheck, '2026-11-03 10:00'));
        $this->assertSame('VIOLATION', $o['{"run_id":1}']);
        $this->assertSame('WARNING', $o['{"state":"stalled"}']);

        $this->sourceTable('closing_processes', fn ($t) => [$t->id(), $t->string('process_type'), $t->date('closing_date')->nullable(), $t->string('status'), $t->integer('error_count')->default(0), $t->integer('total_count')->default(0), $t->text('error_message')->nullable(), $t->dateTime('updated_at')]);
        $this->sourceInsert('closing_processes', [
            ['process_type' => 'supplier_bill', 'closing_date' => '2026-10-31', 'status' => 'failed', 'error_count' => 1, 'total_count' => 40, 'updated_at' => '2026-11-03 09:00:00'],
            ['process_type' => 'buyer_bill', 'closing_date' => '2026-10-31', 'status' => 'completed', 'updated_at' => '2026-11-03 09:00:00'],
        ]);
        $c = new Core\ClosingProcessCheck;
        $o = $this->outcomes($this->exec($c, '2026-11-03 10:00'));
        $this->assertSame('VIOLATION', $o['{"business_date":"2026-10-31","process":"supplier_bill"}']);
        $this->assertSame('HEALTHY', $o['{"business_date":"2026-10-31","process":"buyer_bill"}']);
        $this->sourceInsert('closing_processes', ['process_type' => 'supplier_bill', 'closing_date' => '2026-10-31', 'status' => 'completed', 'updated_at' => '2026-11-03 09:30:00']);
        $this->assertSame('HEALTHY', $this->outcomes($this->exec($c, '2026-11-03 10:00'))['{"business_date":"2026-10-31","process":"supplier_bill"}'], '再実行で復旧');
    }

    private function externalTable(): void
    {
        $this->sourceTable('external_collaboration_data', fn ($t) => [$t->id(), $t->string('edi_type'), $t->string('external_collaboration_partner')->nullable(), $t->string('data_method')->nullable(), $t->string('status'), $t->integer('total_lines')->default(0), $t->integer('success_count')->default(0), $t->integer('failed_count')->default(0), $t->text('debug_message')->nullable(), $t->dateTime('created_at')]);
    }

    public function test_core_external_collaboration_per_partner(): void
    {
        $this->externalTable();
        $this->sourceInsert('external_collaboration_data', [
            ['edi_type' => 'EARNING', 'external_collaboration_partner' => 'SUMATYU', 'status' => 'APPLYING_SUCCESS', 'total_lines' => 100, 'failed_count' => 67, 'created_at' => '2026-11-03 09:00:00'],
            ['edi_type' => 'DEPOSIT', 'external_collaboration_partner' => 'KAISOKU_NET', 'status' => 'VALIDATION_ERROR', 'created_at' => '2026-11-03 09:00:00'],
            ['edi_type' => 'EARNING', 'external_collaboration_partner' => 'INFOMART', 'status' => 'APPLY_WAIT', 'created_at' => '2026-11-03 06:00:00'],
            ['edi_type' => 'DEPOSIT', 'external_collaboration_partner' => 'MOBILE_COLLECT', 'status' => 'APPLY_ERROR', 'created_at' => '2026-11-03 02:00:00'],
        ]);
        $o = $this->outcomes($this->exec(new Core\ExternalCollaborationCheck, '2026-11-03 10:00'));
        $this->assertSame('WARNING', $o['{"collaboration":"EARNING/SUMATYU","state":"failed_lines"}']);
        $this->assertSame('HEALTHY', $o['{"collaboration":"EARNING/SUMATYU"}']);
        $this->assertSame('VIOLATION', $o['{"collaboration":"DEPOSIT/KAISOKU_NET"}']);
        $this->assertSame('WARNING', $o['{"collaboration":"EARNING/INFOMART"}'], 'APPLY_WAIT が 2 時間超');
        $this->assertArrayNotHasKey('{"collaboration":"DEPOSIT/MOBILE_COLLECT"}', $o, '飛脚分は除外（delivery.external_sync で扱う）');
    }

    // ------------------------------------------------------------------ 千里眼

    public function test_search_sync_checks(): void
    {
        $this->sourceTable('srh_search_sync_states', fn ($t) => [$t->string('name'), $t->dateTime('last_started_at')->nullable(), $t->dateTime('last_success_at')->nullable(), $t->dateTime('last_finished_at')->nullable(), $t->integer('last_checked_item_count')->default(0), $t->integer('last_updated_item_count')->default(0), $t->string('status')->nullable(), $t->text('last_error')->nullable()]);
        $full = new Search\SyncItemsFullCheck;
        $fullAt = fn ($at) => $this->outcomes($this->exec($full, $at))['{"business_date":"2026-11-03"}'] ?? 'ABSENT';
        $this->assertSame('ABSENT', $fullAt('2026-11-03 03:30'));
        $this->assertSame('VIOLATION', $fullAt('2026-11-03 04:05'), '状態行なし');
        $this->sourceInsert('srh_search_sync_states', [
            ['name' => 'searchable_items_full', 'status' => 'success', 'last_success_at' => '2026-11-02 03:01:00'],
            ['name' => 'searchable_item_stocks', 'status' => 'success', 'last_success_at' => '2026-11-03 09:50:00'],
        ]);
        $this->assertSame('VIOLATION', $fullAt('2026-11-03 04:05'), '当日の成功なし');
        \DB::connection('monitor_source')->table('srh_search_sync_states')->where('name', 'searchable_items_full')->update(['last_success_at' => '2026-11-03 03:01:59']);
        $this->assertSame('HEALTHY', $fullAt('2026-11-03 04:05'));

        $stocks = new Search\SyncItemStocksCheck;
        $this->assertSame('HEALTHY', $this->only($this->exec($stocks, '2026-11-03 10:00')));
        $this->assertSame('WARNING', $this->only($this->exec($stocks, '2026-11-03 10:40')), '50 分');
        $this->assertSame('VIOLATION', $this->only($this->exec($stocks, '2026-11-03 11:30')), '100 分');
        \DB::connection('monitor_source')->table('srh_search_sync_states')->where('name', 'searchable_item_stocks')->update(['status' => 'error', 'last_error' => 'ES down']);
        $this->assertSame('VIOLATION', $this->only($this->exec($stocks, '2026-11-03 10:00')), 'エラー状態');
    }

    // ------------------------------------------------------------------ 飛脚

    private function opsTable(): void
    {
        $this->sourceTable('dlv_operations', fn ($t) => [$t->id(), $t->string('status'), $t->unsignedBigInteger('user_id')->nullable(), $t->string('app_version')->nullable(), $t->dateTime('finished_at')->nullable(), $t->dateTime('created_at'), $t->dateTime('updated_at'), $t->dateTime('deleted_at')->nullable()]);
    }

    public function test_delivery_operation_sync(): void
    {
        $this->opsTable();
        $c = new Delivery\OperationSyncCheck;
        $this->sourceInsert('dlv_operations', [
            ['status' => 'FAILED_TO_SYNC', 'created_at' => '2026-11-03 08:00:00', 'updated_at' => '2026-11-03 17:00:00'],
            ['status' => 'IN_PROGRESS_SYNC', 'created_at' => '2026-11-03 08:00:00', 'updated_at' => '2026-11-03 17:00:00'],
            ['status' => 'READY_TO_SYNC', 'created_at' => '2026-11-03 08:00:00', 'updated_at' => '2026-11-03 16:30:00'],
            ['status' => 'STARTED', 'created_at' => '2026-11-03 08:00:00', 'updated_at' => '2026-11-03 08:00:00'],
        ]);
        $o = $this->outcomes($this->exec($c, '2026-11-03 17:45'));
        $this->assertSame('VIOLATION', $o['{"state":"failed_to_sync"}']);
        $this->assertSame('VIOLATION', $o['{"state":"in_progress_stuck"}']);
        $this->assertSame('WARNING', $o['{"state":"waiting"}']);
        $this->assertSame('HEALTHY', $o['{"business_date":"2026-11-02","state":"unfinished"}'], '当日分の未完了は対象外');
        $o = $this->outcomes($this->exec($c, '2026-11-04 06:10'));
        $this->assertSame('WARNING', $o['{"business_date":"2026-11-03","state":"unfinished"}'], '前日の未完了が翌 06:00 に残る');
    }

    public function test_delivery_collected_money(): void
    {
        $this->sourceTable('dlv_item_delivery_collected_monies', fn ($t) => [$t->id(), $t->string('cash_fraud_detection_status')->nullable(), $t->boolean('admin_approved')->default(0), $t->boolean('need_admin_check')->default(0), $t->dateTime('admin_check_date')->nullable(), $t->dateTime('created_at'), $t->dateTime('deleted_at')->nullable()]);
        $this->sourceInsert('dlv_item_delivery_collected_monies', [
            ['cash_fraud_detection_status' => 'RECEIPT_PRINTED_MULTIPLE_TIME', 'created_at' => '2026-11-02 15:00:00'],
            ['cash_fraud_detection_status' => 'RECEIPT_NOT_PRINTED', 'created_at' => '2026-11-02 16:00:00'],
            ['cash_fraud_detection_status' => 'RECEIPT_NOT_PRINTED', 'admin_approved' => 1, 'created_at' => '2026-11-02 16:00:00'],
            ['cash_fraud_detection_status' => 'NORMAL', 'need_admin_check' => 1, 'created_at' => '2026-10-30 10:00:00'],
        ]);
        $r = $this->exec(new Delivery\CollectedMoneyCheck, '2026-11-03 09:00');
        $o = $this->outcomes($r);
        $this->assertSame('WARNING', $o['{"business_date":"2026-11-02","state":"fraud_detection"}']);
        $this->assertSame(2, array_sum($r->observations[0]->metrics), '管理者承認済みは除外');
        $this->assertSame('WARNING', $o['{"state":"admin_check"}']);
    }

    public function test_delivery_external_sync_to_core(): void
    {
        $this->opsTable();
        $this->externalTable();
        $c = new Delivery\ExternalSyncCheck;
        $at = fn () => $this->outcomes($this->exec($c, '2026-11-03 02:40'))['{"business_date":"2026-11-02"}'];
        $this->assertArrayNotHasKey('{"business_date":"2026-11-02"}', $this->outcomes($this->exec($c, '2026-11-03 02:20')), '判定時刻前');
        $this->assertSame('HEALTHY', $at(), '前日に運行なし → 対象なし');

        $ops = [];
        for ($i = 0; $i < 6; $i++) {
            $ops[] = ['status' => 'FINISHED', 'finished_at' => '2026-11-02 17:30:00', 'created_at' => '2026-11-02 08:00:00', 'updated_at' => '2026-11-02 17:30:00'];
        }
        $this->sourceInsert('dlv_operations', $ops);
        $this->assertSame('VIOLATION', $at(), '運行ありで連携なし');

        $this->sourceInsert('external_collaboration_data', ['edi_type' => 'EMPTY_CONTAINER_PICKUP', 'external_collaboration_partner' => 'MOBILE_COLLECT', 'status' => 'APPLYING_SUCCESS', 'total_lines' => 10, 'created_at' => '2026-11-03 02:03:00']);
        $this->assertSame('WARNING', $at(), '運行 5 件以上で集金なし');
        $this->sourceInsert('external_collaboration_data', ['edi_type' => 'DEPOSIT', 'external_collaboration_partner' => 'MOBILE_COLLECT', 'status' => 'APPLYING_SUCCESS', 'total_lines' => 4, 'created_at' => '2026-11-03 02:03:10']);
        $this->assertSame('HEALTHY', $at(), '対象日は運行日（前日）');
        $this->sourceInsert('external_collaboration_data', ['edi_type' => 'DEPOSIT', 'external_collaboration_partner' => 'MOBILE_COLLECT', 'status' => 'VALIDATION_ERROR', 'created_at' => '2026-11-03 02:04:00']);
        $this->assertSame('VIOLATION', $at());
    }

    public function test_delivery_container_collect(): void
    {
        $this->sourceTable('dlv_item_deliveries', fn ($t) => [$t->id(), $t->date('issue_date'), $t->dateTime('deleted_at')->nullable()]);
        $this->sourceTable('dlv_empty_container_collects', fn ($t) => [$t->id(), $t->date('issue_date'), $t->dateTime('deleted_at')->nullable()]);
        $c = new Delivery\ContainerCollectCheck;
        $this->assertSame('HEALTHY', $this->only($this->exec($c, '2026-11-03 09:00')), '配送なし');
        $this->sourceInsert('dlv_item_deliveries', array_fill(0, 12, ['issue_date' => '2026-11-02']));
        $this->assertSame('WARNING', $this->only($this->exec($c, '2026-11-03 09:00')));
        $this->sourceInsert('dlv_empty_container_collects', ['issue_date' => '2026-11-02']);
        $this->assertSame('HEALTHY', $this->only($this->exec($c, '2026-11-03 09:00')));
    }

    // ------------------------------------------------------------------ 共通

    public function test_all_new_checks_return_unknown_when_source_is_unavailable(): void
    {
        config()->set('database.connections.monitor_source.database', null);
        \DB::purge('monitor_source');
        $features = app(\App\Monitoring\Services\FeatureRegistry::class);
        foreach (\App\Models\Monitoring\Feature::query()->where('feature_key', 'not like', 'insights.%')->get() as $f) {
            $features->setEnabled($f, true, ['id' => null, 'name' => 't'], 'test');
        }
        \App\Models\Monitoring\Check::query()->update(['next_due_at' => null]);
        app(\App\Monitoring\Services\CheckRunner::class)->run(null, null, true, true);
        $this->assertSame(0, \App\Models\Monitoring\LogEntry::query()->where('source_kind', 'CHECK')->where('check_key', 'not like', 'monitor.%')->whereNotIn('check_key', ['http.probe', 'node.resources'])->where('outcome', 'HEALTHY')->count(), '業務DB未接続で HEALTHY を返さない');
        $this->assertTrue(\App\Models\Monitoring\LogEntry::query()->where('error_code', 'MONITOR_SOURCE_NOT_CONFIGURED')->exists());
    }

    public function test_runner_executes_new_checks_end_to_end_and_creates_incidents(): void
    {
        $this->opsTable();
        $this->sourceInsert('dlv_operations', ['status' => 'FAILED_TO_SYNC', 'created_at' => now('Asia/Tokyo')->format('Y-m-d H:i:s'), 'updated_at' => now('Asia/Tokyo')->format('Y-m-d H:i:s')]);
        $features = app(\App\Monitoring\Services\FeatureRegistry::class);
        $features->setEnabled(\App\Models\Monitoring\Feature::query()->where('feature_key', 'delivery.sync')->sole(), true, ['id' => null, 'name' => 't'], 'test');
        app(\App\Monitoring\Services\CheckRunner::class)->run(null, ['DELIVERY:delivery.operation_sync'], true, true);
        $i = \App\Models\Monitoring\Incident::query()->where('check_key', 'delivery.operation_sync')->sole();
        $this->assertSame('ERROR', $i->severity->value);
        $this->assertSame('RB-17', $i->runbook_key);
        $this->assertSame(1, \App\Models\Monitoring\Notification::query()->where('incident_id', $i->id)->count());
    }
}
