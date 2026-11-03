<?php

namespace Tests\Feature\Monitoring;

use App\Models\Monitoring\AuditEvent;
use App\Models\Monitoring\Check as CheckModel;
use App\Models\Monitoring\Feature;
use App\Models\Monitoring\LogEntry;
use App\Models\Monitoring\Site;
use App\Monitoring\Exceptions\InvalidTransition;
use App\Monitoring\Services\CheckRegistry;
use App\Monitoring\Services\CheckRunner;
use App\Monitoring\Services\FeatureRegistry;
use App\Monitoring\Services\SiteRegistry;
use App\Monitoring\Services\SourceReader;
use Illuminate\Support\Facades\Schema;

/** 仕様書 v2 V-010, V-013, V-020〜V-027 */
class FeatureGateTest extends MonitoringTestCase
{
    private FeatureRegistry $features;

    private array $actor = ['id' => 1, 'name' => '管理 太郎'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootRegistries();
        $this->features = app(FeatureRegistry::class);
    }

    private function feature(string $key): Feature
    {
        return Feature::query()->where('feature_key', $key)->sole();
    }

    public function test_v2_migration_can_go_down_and_up(): void
    {
        $m = require base_path('database/migrations/2026_11_04_000001_create_ins_monitor_v2_tables.php');
        $m->down();
        $this->assertFalse(Schema::connection('monitor_store')->hasTable('monitor_features'));
        $this->assertFalse(Schema::connection('monitor_store')->hasColumn('monitor_checks', 'feature_key'));
        $m->up();
        $this->assertTrue(Schema::connection('monitor_store')->hasTable('monitor_features'));
        $this->assertTrue(Schema::connection('monitor_store')->hasColumn('monitor_checks', 'rule_json'));
    }

    public function test_every_registered_check_belongs_to_a_feature_and_unmapped_check_fails(): void
    {
        app(CheckRegistry::class)->assertFeatureMapping();
        $this->assertSame(0, CheckModel::query()->whereNull('feature_key')->count(), 'sync 後はすべての監視項目に機能キーがある');
        $this->assertNull($this->features->featureForCheck('trade.not_registered'));

        $broken = new class extends CheckRegistry
        {
            public function all(): array
            {
                return ['TRADE:x' => new class extends \App\Monitoring\Checks\AbstractCheck
                {
                    public function definition(): \App\Monitoring\Contracts\CheckDefinition
                    {
                        return new \App\Monitoring\Contracts\CheckDefinition('trade.unmapped', 'TRADE', 'x', 'x', 1, 300, 300, null);
                    }

                    public function evaluate(\App\Monitoring\Services\CheckContext $c): \App\Monitoring\Services\CheckResult
                    {
                        return \App\Monitoring\Services\CheckResult::of();
                    }
                }];
            }
        };
        $this->expectException(\LogicException::class);
        $broken->assertFeatureMapping();
    }

    public function test_features_are_created_per_site_with_defaults(): void
    {
        $this->assertTrue($this->feature('trade.pos_stats')->enabled, 'v1 から稼働中の乃蓮は既定 ON');
        $this->assertFalse($this->feature('wms.auto_order')->enabled, '新規機能は既定 OFF（推奨値を確認して管理者が ON）');
        $this->assertTrue($this->feature('insights.self')->enabled);
        foreach (['core', 'trade', 'wms', 'delivery', 'search'] as $code) {
            $this->assertNotNull(Feature::query()->where('feature_key', $code.'.app_log')->first());
            $this->assertNotNull(Feature::query()->where('feature_key', $code.'.http')->first());
        }
        // 再同期で運用値を上書きしない
        $this->features->setEnabled($this->feature('wms.auto_order'), true, $this->actor, '自動発注を使用');
        app(CheckRegistry::class)->sync(app(SiteRegistry::class));
        $this->assertTrue($this->feature('wms.auto_order')->enabled);
    }

    public function test_three_level_gate(): void
    {
        $coupon = CheckModel::query()->where('check_key', 'trade.coupon_daily')->sole();
        $this->assertTrue($this->features->gate($coupon)['active']);

        // 監視項目だけ無効
        $this->features->setCheckEnabled($coupon, false, $this->actor, '一時停止');
        $this->assertSame('check_disabled', $this->features->gate($coupon->fresh())['reason']);
        $this->features->setCheckEnabled($coupon->fresh(), true, $this->actor, '再開');

        // 機能を無効 → 配下すべて未監視、他機能は影響なし
        $this->features->setEnabled($this->feature('trade.pos_stats'), false, $this->actor, 'POS 未使用');
        $this->assertSame('feature_disabled', $this->features->gate($coupon->fresh())['reason']);
        $mail = CheckModel::query()->where('check_key', 'trade.mail')->sole();
        $this->assertTrue($this->features->gate($mail)['active']);
        $this->features->setEnabled($this->feature('trade.pos_stats'), true, $this->actor, '再開');

        // サービスを無効 → 全項目未監視
        $site = Site::query()->where('application_code', 'TRADE')->sole();
        $this->features->setSiteEnabled($site, false, $this->actor, '乃蓮を利用しない顧客');
        $this->assertSame('site_disabled', $this->features->gate($mail->fresh())['reason']);
        $this->assertSame('未監視（サービス停止）', FeatureRegistry::gateLabel('site_disabled'));
        $this->assertSame('DISABLED', $site->fresh()->coverage->value);
    }

    public function test_inactive_checks_are_not_executed_and_not_reported_stale(): void
    {
        $this->useSourceStub();
        $this->features->setEnabled($this->feature('trade.pos_stats'), false, $this->actor, '未使用');
        $this->features->setEnabled($this->feature('trade.external_send'), false, $this->actor, '未使用');
        app(CheckRunner::class)->run(null, null, true, true);
        $this->assertSame(0, LogEntry::query()->where('check_key', 'like', 'trade.%')->count(), '無効機能のチェックは実行されない');
        // check_stale は無効項目を遅延扱いしない
        CheckModel::query()->where('check_key', 'monitor.check_stale')->update(['next_due_at' => null]);
        CheckModel::query()->where('check_key', 'like', 'trade.%')->update(['created_at' => now('UTC')->subDays(2)]);
        app(CheckRunner::class)->run(null, ['INSIGHTS:monitor.check_stale'], true, true);
        $this->assertSame(0, LogEntry::query()->where('error_code', 'MONITOR_CHECK_STALE')->count());
    }

    public function test_changes_require_reason_and_are_audited(): void
    {
        try {
            $this->features->setEnabled($this->feature('wms.jx'), true, $this->actor, '  ');
            $this->fail('理由なしで変更できてはいけない');
        } catch (InvalidTransition) {
        }
        $this->features->setEnabled($this->feature('wms.jx'), true, $this->actor, 'JX 連携を利用');
        $a = AuditEvent::query()->where('action', 'feature.enable')->sole();
        $this->assertSame('WMS:wms.jx', $a->target_id);
        $this->assertSame(['enabled' => false], $a->before_json);
        $this->assertSame(['enabled' => true], $a->after_json);
        $this->assertSame('管理 太郎', $a->actor_name);
        $this->assertSame('JX 連携を利用', $a->reason);

        $item = CheckModel::query()->where('check_key', 'trade.item_last_use')->sole();
        $this->expectException(InvalidTransition::class);
        $this->features->setCheckEnabled($item, true, $this->actor, '試験'); // 未実装は有効化不可
    }

    public function test_recommendation_detection_from_business_tables(): void
    {
        $this->useSourceStub();
        $this->sourceTable('stats_import_runs', fn ($t) => [$t->id(), $t->timestamp('created_at')->nullable()]);
        $this->sourceInsert('stats_import_runs', ['created_at' => now('Asia/Tokyo')->subDay()->format('Y-m-d H:i:s')]);
        $this->sourceTable('wms_warehouse_auto_order_settings', fn ($t) => [$t->id(), $t->boolean('is_auto_order_enabled')]);
        $this->sourceInsert('wms_warehouse_auto_order_settings', ['is_auto_order_enabled' => 0]);
        $this->sourceTable('wms_failed_jobs', fn ($t) => [$t->id()]);
        Schema::connection('monitor_source')->dropIfExists('srh_search_sync_states');

        $r = $this->features->detect(SourceReader::fromConfig());
        $this->assertTrue($r['core.stats']['recommended']);
        $this->assertFalse($r['wms.auto_order']['recommended'], '有効倉庫が無ければ推奨 OFF');
        $this->assertTrue($r['wms.queue']['recommended']);
        $this->assertFalse($r['search.sync']['recommended'], 'テーブルが無ければ推奨 OFF');
        $this->assertFalse($r['core.app_log']['recommended'], 'ログ収集対象が未登録');
        // 各サーバーの .env で宣言された収集器（台帳への同期前）があれば、アプリログは推奨 ON
        \App\Models\Monitoring\Collector::query()->create(['collector_uuid' => '7c7a2f6e-2222-4e8f-9b7e-000000000009', 'site_id' => $this->feature('core.app_log')->site_id, 'node_code' => 'hana-prod-1', 'credential_ref' => 'X']);
        $r = $this->features->detect(SourceReader::fromConfig());
        $this->assertTrue($r['core.app_log']['recommended'], '収集器が登録されていれば推奨 ON');
        $this->assertFalse($r['wms.app_log']['recommended']);

        // 設定値は変えない、食い違いは要確認
        $f = $this->feature('core.stats');
        $this->assertFalse($f->enabled);
        $this->assertTrue($f->needsReview());
        $this->assertSame(0, \App\Models\Monitoring\Notification::count(), '要確認は通知しない');
    }

    public function test_detection_without_source_is_null(): void
    {
        config()->set('database.connections.monitor_source.database', null);
        $r = $this->features->detect(SourceReader::fromConfig());
        $this->assertNull($r['core.stats']['recommended']);
        $this->assertFalse($this->feature('core.stats')->needsReview());
    }

    public function test_detection_sql_goes_through_source_reader_only(): void
    {
        // 静的検査: 監視チェック・機能検出が monitor_source を直接使っていない（V-027）
        $files = array_merge(glob(app_path('Monitoring/Checks/*/*.php')), [app_path('Monitoring/Services/FeatureRegistry.php')]);
        foreach ($files as $f) {
            $src = file_get_contents($f);
            $this->assertStringNotContainsString("DB::connection('monitor_source')", $src, $f);
            $this->assertDoesNotMatchRegularExpression('/\b(INSERT|UPDATE|DELETE)\s/i', preg_replace('/\/\*.*?\*\/|\/\/[^\n]*/s', '', $src), $f);
        }
    }

    public function test_detect_apply_command_applies_and_audits(): void
    {
        $this->useSourceStub();
        $this->sourceTable('srh_search_sync_states', fn ($t) => [$t->string('name')]);
        $this->sourceInsert('srh_search_sync_states', ['name' => 'searchable_items_full']);
        $this->artisan('monitor:detect-features', ['--apply' => true])->assertSuccessful();
        $this->assertTrue($this->feature('search.sync')->enabled);
        $this->assertTrue(AuditEvent::query()->where('target_id', 'SEARCH:search.sync')->where('actor_name', 'CLI detect-features')->exists());
    }

    public function test_every_check_runbook_is_registered(): void
    {
        $defs = \App\Monitoring\Services\RunbookRegistry::definitions();
        foreach (\App\Monitoring\Services\CheckRegistry::CHECKS as $class) {
            $d = app($class)->definition();
            if ($d->runbookKey) {
                $this->assertArrayHasKey($d->runbookKey, $defs, $d->key.' の対処手順');
            }
        }
        foreach (range(11, 20) as $n) {
            $this->assertArrayHasKey('RB-'.$n, $defs);
        }
    }
}
