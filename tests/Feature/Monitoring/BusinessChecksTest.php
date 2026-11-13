<?php

namespace Tests\Feature\Monitoring;

use App\Monitoring\Checks\Core;
use App\Monitoring\Checks\Delivery;
use App\Monitoring\Checks\Search;
use App\Monitoring\Checks\Trade;
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
        $this->sourceTable('wms_warehouse_auto_order_settings', fn ($t) => [$t->id(), $t->unsignedBigInteger('warehouse_id'), $t->boolean('is_auto_order_enabled'), $t->text('replenishment_config')->nullable()]);
        $this->sourceTable('wms_auto_order_runs', fn ($t) => [$t->id(), $t->string('slot_key'), $t->boolean('is_preview')->default(0), $t->string('status'), $t->unsignedBigInteger('warehouse_id'), $t->integer('failed_count')->default(0), $t->integer('generated_count')->default(0), $t->integer('held_count')->default(0)]);
    }

    public function test_wms_auto_order_run(): void
    {
        $this->wmsAutoOrderTables();
        $c = new Wms\AutoOrderRunCheck;
        $this->assertSame('HEALTHY', $this->only($this->exec($c, '2026-11-03 09:00')), '有効倉庫なし → 対象なし');

        // 倉庫設定（蔵の replenishment_config）: 日〜土、平日 01:00、日曜だけ 22:00（華本番と同じ）
        $cfg = json_encode(['weekdays' => [0, 1, 2, 3, 4, 5, 6], 'execution_time' => '01:00', 'sunday_execution_time' => '22:00']);
        $this->sourceInsert('wms_warehouse_auto_order_settings', [['warehouse_id' => 1, 'is_auto_order_enabled' => 1, 'replenishment_config' => $cfg], ['warehouse_id' => 2, 'is_auto_order_enabled' => 1, 'replenishment_config' => $cfg]]);
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

        // 日曜（2026-11-08）は 01:00 の枠が無く 22:00。02:40 時点で 01:00 枠を未完了にしない（MON-000082 の誤検知）
        $sun = $this->outcomes($this->exec($c, '2026-11-08 02:40'));
        $this->assertArrayNotHasKey('{"business_date":"2026-11-08","slot":"01:00"}', $sun, '日曜に 01:00 枠は無い');
        $this->assertSame('VIOLATION', $this->outcomes($this->exec($c, '2026-11-08 23:31'))['{"business_date":"2026-11-08","slot":"22:00"}'], '日曜 22:00＋90 分で未完了');
        $this->sourceInsert('wms_auto_order_runs', [['slot_key' => '2026-11-08:22:00', 'status' => 'COMPLETED', 'warehouse_id' => 1], ['slot_key' => '2026-11-08:22:00', 'status' => 'COMPLETED', 'warehouse_id' => 2]]);
        $this->assertSame('HEALTHY', $this->outcomes($this->exec($c, '2026-11-08 23:31'))['{"business_date":"2026-11-08","slot":"22:00"}']);
        // 倉庫ごとに予定が違う場合は、枠ごとの対象倉庫数で判定する
        \DB::connection('monitor_source')->table('wms_warehouse_auto_order_settings')->where('warehouse_id', 2)->update(['replenishment_config' => json_encode(['weekdays' => [1, 2, 3, 4, 5], 'execution_time' => '03:00'])]);
        $mon = $this->outcomes($this->exec($c, '2026-11-09 04:31'));
        $this->sourceInsert('wms_auto_order_runs', ['slot_key' => '2026-11-09:01:00', 'status' => 'COMPLETED', 'warehouse_id' => 1]);
        $this->assertSame('VIOLATION', $mon['{"business_date":"2026-11-09","slot":"03:00"}'], '倉庫 2 の 03:00 枠');
        $this->assertSame('HEALTHY', $this->outcomes($this->exec($c, '2026-11-09 04:31'))['{"business_date":"2026-11-09","slot":"01:00"}'], '倉庫 1 だけの 01:00 枠は 1 倉庫で完了');
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
        $this->sourceTable('stats_import_runs', fn ($t) => [$t->id(), $t->string('job_type'), $t->string('source_system'), $t->string('status'), $t->date('period_from')->nullable(), $t->date('period_to')->nullable(), $t->dateTime('started_at'), $t->dateTime('finished_at')->nullable(), $t->text('error_message')->nullable(), $t->dateTime('created_at')->nullable()]);
        $this->sourceTable('earnings', fn ($t) => [$t->id(), $t->date('account_date'), $t->boolean('is_active')->default(1), $t->dateTime('updated_at')]);
        $this->sourceTable('stats_daily_buyer_trade_profits', fn ($t) => [$t->id(), $t->date('stats_date'), $t->dateTime('finalized_at')->nullable()]);
        $this->sourceTable('stats_daily_department_trade_profits', fn ($t) => [$t->id(), $t->date('stats_date')]);
    }

    private function agg(string $date, string $startedAt, string $status = 'succeeded', string $jobType = 'daily_aggregate', ?string $err = null): void
    {
        $this->sourceInsert('stats_import_runs', [['job_type' => $jobType, 'source_system' => 'core_earning', 'status' => $status, 'period_from' => $date, 'period_to' => $date, 'started_at' => $startedAt, 'error_message' => $err]]);
    }

    /** 予定枠: 更新あり＋子記録なし → 起動なし、更新なし → 対象なし（推定）、失敗・長時間実行、後続の枠で処理済みなら解消 */
    public function test_core_stats_job_slot_without_core_records(): void
    {
        $this->statsTable();
        $c = new Core\StatsJobSlotCheck;
        $cfg = ['slots' => ['07:00', '08:10']];
        $k = fn ($slot) => '{"business_date":"2026-11-03","slot":"'.$slot.'"}';

        // 07:00 の更新窓（02:00〜07:00）に有効伝票の更新あり、無効伝票は数えない
        $this->sourceInsert('earnings', [
            ['account_date' => '2026-11-02', 'is_active' => 1, 'updated_at' => '2026-11-03 03:00:00'],
            ['account_date' => '2026-11-03', 'is_active' => 1, 'updated_at' => '2026-11-03 06:30:00'],
            ['account_date' => '2026-10-20', 'is_active' => 0, 'updated_at' => '2026-11-03 05:48:00'],
        ]);
        $this->assertArrayNotHasKey($k('07:00'), array_filter($this->outcomes($this->exec($c, '2026-11-03 07:03', $cfg)), fn ($v) => $v !== 'NOT_DUE'), '開始猶予内');
        $this->assertSame('VIOLATION', $this->outcomes($this->exec($c, '2026-11-03 07:06', $cfg))[$k('07:00')], '更新ありなのに起動なし');

        // 子記録あり（実行中）→ 正常、期限超過で running → 違反、完了 → 正常
        $this->agg('2026-11-02', '2026-11-03 07:00:05', 'running');
        $this->assertSame('HEALTHY', $this->outcomes($this->exec($c, '2026-11-03 07:20', $cfg))[$k('07:00')]);
        $this->assertSame('VIOLATION', $this->outcomes($this->exec($c, '2026-11-03 07:40', $cfg))[$k('07:00')], '長時間実行');
        \Illuminate\Support\Facades\DB::connection('monitor_source')->table('stats_import_runs')->update(['status' => 'succeeded']);
        $this->assertSame('HEALTHY', $this->outcomes($this->exec($c, '2026-11-03 07:40', $cfg))[$k('07:00')]);

        // 08:10: 更新窓（02:10〜08:10）に更新あり → 子記録なし → 違反。失敗 → 違反
        $this->assertSame('VIOLATION', $this->outcomes($this->exec($c, '2026-11-03 08:20', $cfg))[$k('08:10')]);
        $this->agg('2026-11-03', '2026-11-03 08:10:05', 'failed', 'daily_aggregate', 'Deadlock');
        $this->assertSame('VIOLATION', $this->outcomes($this->exec($c, '2026-11-03 08:20', $cfg))[$k('08:10')], '失敗');

        // 更新の無い枠は「対象なし（推定）」で正常
        \Illuminate\Support\Facades\DB::connection('monitor_source')->table('earnings')->delete();
        \Illuminate\Support\Facades\DB::connection('monitor_source')->table('stats_import_runs')->delete();
        $this->assertSame('HEALTHY', $this->outcomes($this->exec($c, '2026-11-03 07:06', $cfg))[$k('07:00')]);
    }

    /** 継続追跡: 判定範囲（26 時間）を外れても未解決の枠は再確認し、対象日が後続の処理で集計されれば解消する */
    public function test_core_stats_job_slot_keeps_tracking_and_recovers_when_dates_are_covered_later(): void
    {
        $this->statsTable();
        $this->bootRegistries();
        $c = new Core\StatsJobSlotCheck;
        $cfg = ['slots' => ['07:00']];
        $this->sourceInsert('earnings', [['account_date' => '2026-10-31', 'is_active' => 1, 'updated_at' => '2026-11-01 05:00:00']]);
        app(\App\Monitoring\Services\ObservationIngestor::class)->ingestInternal([$this->event(['application_code' => 'CORE', 'check_key' => 'core.stats_job_slot', 'scope' => ['business_date' => '2026-11-01', 'slot' => '07:00'], 'business_date' => '2026-11-01', 'summary' => '起動なし', 'error_code' => 'STATS_JOB_NOT_STARTED'])]);
        $key = '{"business_date":"2026-11-01","slot":"07:00"}';
        $this->assertSame('VIOLATION', $this->outcomes($this->exec($c, '2026-11-03 12:00', $cfg))[$key], '2 日前の枠も未解決なら再確認する');
        $this->agg('2026-10-31', '2026-11-03 11:00:00');
        $this->assertSame('HEALTHY', $this->outcomes($this->exec($c, '2026-11-03 12:00', $cfg))[$key], '対象日が後続で集計されたので解消');
    }

    /** 対象日別（抽出漏れ疑い）: 更新のあった計上日ごとに集計・部門同期の成功を確認。別の日の成功では解消しない */
    public function test_core_stats_job_dates_flags_suspect_missing_dates(): void
    {
        $this->statsTable();
        $c = new Core\StatsJobDatesCheck;
        $cfg = ['slots' => ['07:00'], 'settle_minutes' => 30];
        $this->sourceInsert('earnings', [
            ['account_date' => '2026-11-01', 'is_active' => 1, 'updated_at' => '2026-11-03 03:00:00'],
            ['account_date' => '2026-11-02', 'is_active' => 1, 'updated_at' => '2026-11-03 04:00:00'],
            ['account_date' => '2026-11-03', 'is_active' => 1, 'updated_at' => '2026-11-03 06:00:00'],
        ]);
        $this->agg('2026-11-01', '2026-11-03 07:00:05');
        $this->agg('2026-11-01', '2026-11-03 07:00:07', 'succeeded', 'daily_department_sync');
        $this->agg('2026-11-02', '2026-11-03 07:00:09');
        $this->agg('2026-11-02', '2026-11-03 07:00:11', 'failed', 'daily_department_sync', 'x');
        $this->agg('2026-11-03', '2026-11-02 19:00:00'); // 枠より前の古い成功は数えない
        $this->assertArrayNotHasKey('{"business_date":"2026-11-01","kind":"aggregate"}', $this->outcomes($this->exec($c, '2026-11-03 07:20', $cfg)), '枠の完了を 30 分待つ');
        $o = $this->outcomes($this->exec($c, '2026-11-03 07:40', $cfg));
        $this->assertSame('HEALTHY', $o['{"business_date":"2026-11-01","kind":"aggregate"}']);
        $this->assertSame('HEALTHY', $o['{"business_date":"2026-11-01","kind":"dept"}']);
        $this->assertSame('WARNING', $o['{"business_date":"2026-11-02","kind":"dept"}'], '部門同期が失敗');
        $this->assertSame('WARNING', $o['{"business_date":"2026-11-03","kind":"aggregate"}'], '3 日中 1 日未集計');
        $this->agg('2026-11-03', '2026-11-03 08:11:00');
        $o = $this->outcomes($this->exec($c, '2026-11-03 08:40', $cfg));
        $this->assertSame('HEALTHY', $o['{"business_date":"2026-11-03","kind":"aggregate"}']);
        $this->assertSame('WARNING', $o['{"business_date":"2026-11-02","kind":"dept"}'], '別の日の成功では解消しない');
    }

    /** 前日分の日次確定: 確定記録＋確定印＋部門統計が期限までに揃う。別の日付の成功で代用しない */
    public function test_core_stats_daily_finalize_requires_same_target_date(): void
    {
        $this->statsTable();
        $c = new Core\StatsDailyFinalizeCheck;
        $this->assertArrayNotHasKey('{"business_date":"2026-11-02"}', $this->outcomes($this->exec($c, '2026-11-03 02:30')), '期限前');
        $this->assertSame('VIOLATION', $this->outcomes($this->exec($c, '2026-11-03 02:50'))['{"business_date":"2026-11-02"}']);
        $this->sourceInsert('stats_import_runs', [['job_type' => 'daily_finalize', 'source_system' => 'core_earning', 'status' => 'succeeded', 'period_from' => '2026-11-01', 'period_to' => '2026-11-01', 'started_at' => '2026-11-03 02:05:00']]);
        $this->assertSame('VIOLATION', $this->outcomes($this->exec($c, '2026-11-03 02:50'))['{"business_date":"2026-11-02"}'], '別の日の成功では解消しない');
        $this->sourceInsert('stats_import_runs', [['job_type' => 'daily_finalize', 'source_system' => 'core_earning', 'status' => 'succeeded', 'period_from' => '2026-11-02', 'period_to' => '2026-11-02', 'started_at' => '2026-11-03 02:06:00']]);
        $this->sourceInsert('stats_daily_buyer_trade_profits', [['stats_date' => '2026-11-02', 'finalized_at' => null]]);
        $this->assertSame('VIOLATION', $this->outcomes($this->exec($c, '2026-11-03 02:50'))['{"business_date":"2026-11-02"}'], '部門統計が無い');
        $this->sourceInsert('stats_daily_department_trade_profits', [['stats_date' => '2026-11-02']]);
        // finalized_at が NULL でも（再集計で作り直された行）異常にしない
        $this->assertSame('HEALTHY', $this->outcomes($this->exec($c, '2026-11-03 02:50'))['{"business_date":"2026-11-02"}']);
        $this->sourceInsert('stats_import_runs', [['job_type' => 'daily_finalize', 'source_system' => 'core_earning', 'status' => 'succeeded', 'period_from' => '2026-11-03', 'period_to' => '2026-11-03', 'started_at' => '2026-11-04 02:06:00']]);
        $this->assertSame('HEALTHY', $this->outcomes($this->exec($c, '2026-11-04 02:50'))['{"business_date":"2026-11-03"}'], '売上の無い日は確定記録だけで正常');
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

        // 操作上の拒否（Core の status_code。closing_process_logs の error 行）は通知しない（MON-000084: 取消前の再締め → 74130）
        $this->sourceTable('closing_process_logs', fn ($t) => [$t->id(), $t->unsignedBigInteger('closing_process_id'), $t->string('level'), $t->string('step'), $t->string('message'), $t->json('context')->nullable(), $t->dateTime('created_at')]);
        $key = '{"business_date":"2026-10-31","process":"supplier_bill"}';
        $fail = function (int $code = 0) {
            $id = \DB::connection('monitor_source')->table('closing_processes')->insertGetId(['process_type' => 'supplier_bill', 'closing_date' => '2026-10-31', 'status' => 'failed', 'error_count' => 0, 'total_count' => 1, 'error_message' => '締め処理でエラーが発生しました', 'updated_at' => '2026-11-03 09:45:00']);
            $this->sourceInsert('closing_process_logs', ['closing_process_id' => $id, 'level' => 'error', 'step' => 'complete', 'message' => '締め処理が失敗しました', 'context' => $code ? json_encode(['status_code' => $code]) : null, 'created_at' => '2026-11-03 09:45:00']);
        };
        $fail(74130);
        $r = $this->exec($c, '2026-11-03 10:00');
        $this->assertSame('HEALTHY', $this->outcomes($r)[$key], '既に締め済み（二重締めの防止）は障害にしない');
        $this->assertStringContainsString('操作上の拒否', collect($r->observations)->first(fn ($o) => $o->scope === ['process' => 'supplier_bill', 'business_date' => '2026-10-31'])->summary);
        $fail(72001);
        $this->assertSame('HEALTHY', $this->outcomes($this->exec($c, '2026-11-03 10:00'))[$key], '締め対象の取引なし');
        $fail(78001);
        $this->assertSame('VIOLATION', $this->outcomes($this->exec($c, '2026-11-03 10:00'))[$key], 'それ以外の status_code は従来どおり ERROR');
        $fail(0);
        $this->assertSame('VIOLATION', $this->outcomes($this->exec($c, '2026-11-03 10:00'))[$key], 'status_code の無い失敗（例外）も ERROR');
        // 監視ルールで対象コードを変えられる
        $fail(74130);
        $this->assertSame('VIOLATION', $this->outcomes($this->exec($c, '2026-11-03 10:00', ['ignore_status_codes' => [72001]]))[$key]);
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
        $this->assertSame('ABSENT', $fullAt('2026-11-03 03:20'));
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
    // ------------------------------------------------------------------ 乃蓮（本番の状態値は小文字: sent / failed / succeeded）

    /** 華 本番で確認した実値（ret_report_mail_send_runs・ret_intage_pos_send_runs = sent|failed、ret_company_daily_sales_report_runs = succeeded|running|failed）で完了と判定できること。初回リリースで 'SUCCESS' 固定のため誤検知した回帰テスト。 */
    public function test_trade_fixed_point_skips_slot_before_creation(): void
    {
        $this->sourceTable('ret_fixed_point_pos_schedules', fn ($t) => [$t->id(), $t->boolean('schedule_enabled'), $t->date('starts_on'), $t->date('ends_on'), $t->string('schedule_time'), $t->dateTime('schedule_last_run_at')->nullable(), $t->dateTime('schedule_next_run_at')->nullable(), $t->dateTime('created_at')->nullable()]);
        $this->sourceInsert('ret_fixed_point_pos_schedules', [
            // 当日 13:31 に作成、07:00 枠（MON-000089）→ 当日は判定しない
            ['id' => 12, 'schedule_enabled' => 1, 'starts_on' => '2026-11-13', 'ends_on' => '2026-12-31', 'schedule_time' => '07:00', 'schedule_last_run_at' => null, 'schedule_next_run_at' => '2026-11-14 07:00:00', 'created_at' => '2026-11-13 13:31:45'],
            // 前日以前に作成、08:00 枠が未実行 → 従来どおり違反
            ['id' => 6, 'schedule_enabled' => 1, 'starts_on' => '2026-09-21', 'ends_on' => '2026-12-31', 'schedule_time' => '08:00', 'schedule_last_run_at' => '2026-11-12 08:00:01', 'schedule_next_run_at' => '2026-11-13 08:00:00', 'created_at' => '2026-09-20 19:40:15'],
            // 当日の枠より前に作成（当日 05:00 作成、07:00 枠）→ 判定する
            ['id' => 14, 'schedule_enabled' => 1, 'starts_on' => '2026-11-13', 'ends_on' => '2026-12-31', 'schedule_time' => '07:00', 'schedule_last_run_at' => '2026-11-13 07:00:02', 'schedule_next_run_at' => '2026-11-14 07:00:00', 'created_at' => '2026-11-13 05:00:00'],
        ]);
        $o = $this->outcomes($this->exec(new Trade\FixedPointCheck, '2026-11-13 13:46'));
        $this->assertArrayNotHasKey('{"business_date":"2026-11-13","schedule_id":12}', $o, '作成前の枠は存在しない');
        $this->assertSame('VIOLATION', $o['{"business_date":"2026-11-13","schedule_id":6}']);
        $this->assertSame('HEALTHY', $o['{"business_date":"2026-11-13","schedule_id":14}']);
        // 翌日は #12 も判定対象
        $this->assertSame('VIOLATION', $this->outcomes($this->exec(new Trade\FixedPointCheck, '2026-11-14 09:01'))['{"business_date":"2026-11-14","schedule_id":12}']);
    }

    public function test_trade_status_values_are_matched_case_insensitively(): void
    {
        $this->sourceTable('ret_report_mail_schedules', fn ($t) => [$t->id(), $t->string('report_type'), $t->string('slot_key'), $t->string('schedule_time'), $t->integer('business_date_offset')->default(0), $t->boolean('schedule_enabled')->default(1), $t->string('schedule_type')->default('daily'), $t->dateTime('paused_at')->nullable()]);
        $this->sourceTable('ret_report_mail_send_runs', fn ($t) => [$t->id(), $t->unsignedBigInteger('report_mail_schedule_id'), $t->date('business_date'), $t->string('status')]);
        $this->sourceInsert('ret_report_mail_schedules', [['report_type' => 'net_sales_flash', 'slot_key' => 'daily_0800', 'schedule_time' => '08:00', 'business_date_offset' => -1]]);
        $this->sourceInsert('ret_report_mail_send_runs', [['report_mail_schedule_id' => 1, 'business_date' => '2026-11-02', 'status' => 'failed'], ['report_mail_schedule_id' => 1, 'business_date' => '2026-11-02', 'status' => 'sent']]);
        $this->assertSame('HEALTHY', $this->only($this->exec(new \App\Monitoring\Checks\Trade\ReportMailCheck, '2026-11-03 09:00')), '先行失敗があっても sent があれば完了');
        $this->assertSame('VIOLATION', $this->only($this->exec(new \App\Monitoring\Checks\Trade\ReportMailCheck, '2026-11-04 09:00')), '翌日分は未送信');

        $this->sourceTable('ret_intage_pos_send_settings', fn ($t) => [$t->id(), $t->boolean('schedule_enabled')->default(1), $t->string('schedule_time'), $t->integer('business_date_offset')->default(-1)]);
        $this->sourceTable('ret_intage_pos_send_runs', fn ($t) => [$t->id(), $t->date('target_to_date'), $t->string('status')]);
        $this->sourceInsert('ret_intage_pos_send_settings', [['schedule_time' => '09:30', 'business_date_offset' => -1]]);
        $this->sourceInsert('ret_intage_pos_send_runs', [['target_to_date' => '2026-11-02', 'status' => 'sent']]);
        $this->assertSame('HEALTHY', $this->only($this->exec(new \App\Monitoring\Checks\Trade\IntageSendCheck, '2026-11-03 10:30')));

        $this->sourceTable('ret_daily_sales_report_schedules', fn ($t) => [$t->id(), $t->boolean('schedule_enabled')->default(1), $t->string('schedule_type')->default('daily'), $t->string('schedule_time'), $t->dateTime('schedule_last_run_at')->nullable()]);
        $this->sourceTable('ret_company_daily_sales_report_runs', fn ($t) => [$t->id(), $t->date('business_date'), $t->string('status')]);
        $this->sourceInsert('ret_daily_sales_report_schedules', [['schedule_time' => '06:00']]);
        $this->sourceInsert('ret_company_daily_sales_report_runs', [['business_date' => '2026-11-01', 'status' => 'running'], ['business_date' => '2026-11-01', 'status' => 'succeeded']]);
        $this->assertSame('HEALTHY', $this->only($this->exec(new \App\Monitoring\Checks\Trade\DailyReportCheck, '2026-11-02 08:00')));
        $this->assertSame(0, \App\Monitoring\Checks\Trade\TradeCheck::completedCount(['failed' => 2, 'running' => 1]));
    }
}
