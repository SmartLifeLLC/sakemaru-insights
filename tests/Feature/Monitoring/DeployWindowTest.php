<?php

namespace Tests\Feature\Monitoring;

use App\Models\Monitoring\Check as CheckModel;
use App\Models\Monitoring\Collector;
use App\Monitoring\Checks\Monitor\CheckStaleCheck;
use App\Monitoring\Checks\Monitor\CollectorStaleCheck;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\DeployWindow;
use App\Monitoring\Services\MonitorHealthService;
use App\Monitoring\Services\SourceReader;
use Carbon\CarbonImmutable;

/** デプロイ窓: timer を止めている間の「完了していない」「heartbeat 停止」を障害にしない。 */
class DeployWindowTest extends MonitoringTestCase
{
    public function test_overlap_and_effective_age(): void
    {
        $w = app(DeployWindow::class);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-05 06:56:00', 'UTC'));
        $w->start('hana-prod-1', 30, 'デプロイ', ['id' => null, 'name' => 'deploy']);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-05 07:07:00', 'UTC'));
        $w->end('hana-prod-1', ['id' => null, 'name' => 'deploy']);
        $now = CarbonImmutable::parse('2026-11-05 07:08:00', 'UTC');
        $this->assertSame(11 * 60, $w->overlapSeconds($now->subMinutes(14), $now), '14 分のうち 11 分がデプロイ窓');
        $this->assertSame(3 * 60, $w->effectiveAge(14 * 60, $now));
        $this->assertSame(0, $w->effectiveAge(5 * 60, $now->subMinutes(2)), '窓の中は 0');
        $this->assertSame([], $w->active($now), '終了済み');
        // 終了の記録が漏れても予定時間で閉じる
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-05 08:00:00', 'UTC'));
        $w->start('hana-prod-2', 10, 'デプロイ', ['id' => null, 'name' => 'deploy']);
        $this->assertCount(1, $w->active(CarbonImmutable::parse('2026-11-05 08:05:00', 'UTC')));
        $this->assertCount(0, $w->active(CarbonImmutable::parse('2026-11-05 08:11:00', 'UTC')));
        CarbonImmutable::setTestNow();
    }

    public function test_stale_checks_ignore_deploy_window(): void
    {
        $this->bootRegistries();
        $now = CarbonImmutable::parse('2026-11-05 07:08:00', 'UTC');
        // 14 分前に完了したチェック（上限 10 分）と、14 分前が最後の heartbeat の収集器
        $c = CheckModel::query()->where('check_key', 'monitor.notification')->sole();
        $c->forceFill(['last_completed_at' => $now->subMinutes(14), 'interval_seconds' => 300, 'grace_seconds' => 300])->save();
        Collector::query()->create(['collector_uuid' => '7c7a2f6e-3333-4e8f-9b7e-000000000001', 'site_id' => $c->site_id, 'node_code' => 'hana-prod-2', 'credential_ref' => 'X', 'last_heartbeat_at' => $now->subMinutes(14)]);
        $ctx = fn ($check) => new CheckContext(SourceReader::fromConfig(), $now, $check->definition()->defaultConfig);
        $find = fn ($r, $key) => collect($r->observations)->first(fn ($o) => ($o->scope['check'] ?? $o->scope['node'] ?? null) === $key);

        $this->assertSame('VIOLATION', $find(app(CheckStaleCheck::class)->evaluate($ctx(app(CheckStaleCheck::class))), 'monitor.notification')->outcome->value, 'デプロイ窓なし');
        $this->assertSame('VIOLATION', $find(app(CollectorStaleCheck::class)->evaluate($ctx(app(CollectorStaleCheck::class))), 'hana-prod-2')->outcome->value);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-05 06:56:00', 'UTC'));
        app(DeployWindow::class)->start('hana-prod-1', 30, 'デプロイ deploy/hana', ['id' => null, 'name' => 'deploy']);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-05 07:07:00', 'UTC'));
        app(DeployWindow::class)->end('hana-prod-1', ['id' => null, 'name' => 'deploy']);
        CarbonImmutable::setTestNow();
        $this->assertSame('HEALTHY', $find(app(CheckStaleCheck::class)->evaluate($ctx(app(CheckStaleCheck::class))), 'monitor.notification')->outcome->value, '11 分のデプロイ窓を差し引くと 3 分 → 正常');
        $this->assertSame('HEALTHY', $find(app(CollectorStaleCheck::class)->evaluate($ctx(app(CollectorStaleCheck::class))), 'hana-prod-2')->outcome->value);

        // 窓を超えて止まっていれば検知する
        $c->forceFill(['last_completed_at' => $now->subMinutes(25)])->save();
        $this->assertSame('VIOLATION', $find(app(CheckStaleCheck::class)->evaluate($ctx(app(CheckStaleCheck::class))), 'monitor.notification')->outcome->value);

        // コマンドとお知らせ
        $this->artisan('monitor:deploy-window', ['action' => 'start', '--minutes' => 15, '--node' => 'hana-prod-1'])->expectsOutputToContain('deploy window start')->assertSuccessful();
        $texts = array_column(app(MonitorHealthService::class)->banners(), 'text');
        $this->assertNotEmpty(array_filter($texts, fn ($t) => str_contains($t, 'デプロイ中（hana-prod-1')));
        $this->artisan('monitor:deploy-window', ['action' => 'end', '--node' => 'hana-prod-1'])->expectsOutputToContain('deploy window end')->assertSuccessful();
        $this->artisan('monitor:deploy-window', ['action' => 'status'])->expectsOutputToContain('ありません')->assertSuccessful();
    }
}
