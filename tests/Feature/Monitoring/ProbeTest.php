<?php

namespace Tests\Feature\Monitoring;

use App\Models\Monitoring\Counter;
use App\Models\Monitoring\Feature;
use App\Models\Monitoring\Incident;
use App\Models\Monitoring\ProbeResult;
use App\Models\Monitoring\Target;
use App\Monitoring\Checks\Monitor\HttpProbeCheck;
use App\Monitoring\Checks\Monitor\ProbeStaleCheck;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\CheckRunner;
use App\Monitoring\Services\ProbeRunner;
use App\Monitoring\Services\SourceReader;
use App\Monitoring\Services\TargetService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/** v2 V-050〜055: HTTP 外形監視 */
class ProbeTest extends MonitoringTestCase
{
    private const ACTOR = ['id' => 1, 'name' => '管理者'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootRegistries();
        config()->set('monitoring.probe.allowed_hosts', ['wms.example.jp', '.lw-hana.net']);
    }

    private function target(string $site = 'WMS', string $url = 'https://wms.example.jp/up'): Target
    {
        return app(TargetService::class)->createProbe($site, '/up', $url, self::ACTOR, '死活監視を開始');
    }

    private function runProbe(?CarbonImmutable $now = null): void
    {
        app(CheckRunner::class)->run(null, ['INSIGHTS:http.probe'], true, true);
    }

    public function test_url_validation_restricts_to_own_services(): void
    {
        $this->assertSame('https://wms.example.jp/up', TargetService::validateProbeUrl('https://WMS.example.jp/up#x'));
        $this->assertSame('https://delivery.lw-hana.net/up', TargetService::validateProbeUrl('https://delivery.lw-hana.net/up'));
        foreach (['http://wms.example.jp/up', 'https://evil.example.com/up', 'https://user:pw@wms.example.jp/up', 'ftp://wms.example.jp', 'wms.example.jp/up', 'https://lw-hana.net.evil.com/up'] as $bad) {
            try {
                TargetService::validateProbeUrl($bad);
                $this->fail($bad.' は拒否されるべき');
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        config()->set('monitoring.probe.allowed_hosts', []);
        $this->expectException(\InvalidArgumentException::class);
        TargetService::validateProbeUrl('https://wms.example.jp/up');
    }

    public function test_create_requires_reason_audits_and_enables_site_http_feature(): void
    {
        try {
            app(TargetService::class)->createProbe('WMS', '/up', 'https://wms.example.jp/up', self::ACTOR, '');
            $this->fail();
        } catch (\InvalidArgumentException) {
        }
        $t = $this->target();
        $this->assertSame('HTTP_PROBE', $t->kind);
        $this->assertTrue(\App\Models\Monitoring\AuditEvent::query()->where('action', 'target.create')->exists());
        $this->assertTrue(Feature::query()->where('feature_key', 'wms.http')->sole()->enabled);
        $this->expectException(\InvalidArgumentException::class);
        app(TargetService::class)->createProbe('INSIGHTS', '/up', 'https://wms.example.jp/up', self::ACTOR, '自分自身は不可');
    }

    public function test_success_records_result_counters_and_uses_user_agent_without_redirects(): void
    {
        Http::fake(['wms.example.jp/*' => Http::response('ok', 200)]);
        $t = $this->target();
        $this->runProbe();
        $r = ProbeResult::query()->sole();
        $this->assertTrue($r->ok);
        $this->assertSame(200, (int) $r->http_status);
        $this->assertNotNull($r->duration_ms);
        $this->assertGreaterThan(now('UTC')->addDays(29), $r->expires_at, '30 日保持');
        Http::assertSent(fn ($req) => $req->url() === 'https://wms.example.jp/up' && str_starts_with($req->header('User-Agent')[0] ?? '', 'SakemaruInsightsProbe/'));
        Http::assertSentCount(1);
        $c = Counter::query()->where('counter_key', 'probe_hourly')->sole();
        $this->assertSame(1, (int) $c->counts_json['n']);
        $this->assertSame(0, (int) $c->counts_json['fail']);
        $a = app(ProbeRunner::class)->availabilityBySite(24)[(int) $t->site_id];
        $this->assertSame(100.0, (float) $a['availability']);
        $this->assertSame(0, Incident::count());
    }

    public function test_failures_warn_once_then_error_after_three_and_recover_on_target_site(): void
    {
        Http::fake(['wms.example.jp/*' => Http::sequence()->push('down', 503)->push('down', 503)->push('down', 503)->push('ok', 200)->push('ok', 200)]);
        $t = $this->target();
        $check = app(HttpProbeCheck::class);
        $ctx = fn () => new CheckContext(SourceReader::fromConfig(), CarbonImmutable::now('UTC'), $check->definition()->defaultConfig);

        $o1 = $check->evaluate($ctx())->observations;
        $this->assertSame('WARNING', $o1[0]->outcome->value, '1 回目は WARNING');
        $this->assertSame('WMS', $o1[0]->applicationCode, '観測は対象サービスに帰属');
        $check->evaluate($ctx());

        // 3 回目は runner 経由でインシデント化を確認
        $this->runProbe();
        $i = Incident::query()->sole();
        $this->assertSame('ERROR', $i->severity->value);
        $this->assertSame((int) $t->site_id, (int) $i->site_id);
        $this->assertSame('RB-20', $i->runbook_key);
        $this->assertSame('HTTP_PROBE_FAILED', $i->error_code);

        $this->travel(2)->minutes();
        $this->runProbe();
        $this->assertSame('ACTIVE', $i->fresh()->observation_state->value, '1 回の正常では戻らない');
        $this->travel(2)->minutes();
        $this->runProbe();
        $this->assertSame('RECOVERED', $i->fresh()->observation_state->value);
        $this->assertSame(5, ProbeResult::count());
    }

    public function test_timeouts_and_redirects_are_failures(): void
    {
        $n = 0;
        Http::fake(['wms.example.jp/*' => function () use (&$n) {
            if ($n++ === 0) {
                throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out');
            }

            return Http::response('', 302, ['Location' => 'https://evil.example.com/']);
        }]);
        $this->target();
        app(ProbeRunner::class)->run(CarbonImmutable::now('UTC'));
        $this->assertSame('TIMEOUT', ProbeResult::query()->sole()->error_code);

        app(ProbeRunner::class)->run(CarbonImmutable::now('UTC'));
        $this->assertSame('HTTP_REDIRECT', ProbeResult::query()->orderByDesc('id')->first()->error_code);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'evil'));
    }

    public function test_disabled_feature_or_target_is_not_probed_and_unlisted_host_is_blocked(): void
    {
        Http::fake();
        $t = $this->target();
        app(\App\Monitoring\Services\FeatureRegistry::class)->setEnabled(Feature::query()->where('feature_key', 'wms.http')->sole(), false, self::ACTOR, '機能停止');
        app(\App\Monitoring\Services\FeatureRegistry::class)->forget();
        $this->assertSame([], app(ProbeRunner::class)->run(CarbonImmutable::now('UTC')));
        Http::assertNothingSent();

        app(\App\Monitoring\Services\FeatureRegistry::class)->setEnabled(Feature::query()->where('feature_key', 'wms.http')->sole(), true, self::ACTOR, '再開');
        app(\App\Monitoring\Services\FeatureRegistry::class)->forget();
        config()->set('monitoring.probe.allowed_hosts', ['other.example.jp']); // 登録後に許可から外れた
        app(ProbeRunner::class)->run(CarbonImmutable::now('UTC'));
        Http::assertNothingSent();
        $this->assertSame('URL_NOT_ALLOWED', ProbeResult::query()->sole()->error_code);
    }

    public function test_probe_stale_after_five_minutes(): void
    {
        Http::fake(['wms.example.jp/*' => Http::response('ok', 200)]);
        $check = app(ProbeStaleCheck::class);
        $eval = fn (CarbonImmutable $now) => $check->evaluate(new CheckContext(SourceReader::fromConfig(), $now, $check->definition()->defaultConfig))->observations[0]->outcome->value;
        $now = CarbonImmutable::now('UTC');
        $this->assertSame('HEALTHY', $eval($now), '対象なし');
        $this->target();
        $this->assertSame('VIOLATION', $eval($now), '対象ありで未実行');
        app(ProbeRunner::class)->run($now);
        $this->assertSame('HEALTHY', $eval($now->addMinutes(4)));
        $this->assertSame('VIOLATION', $eval($now->addMinutes(6)));
    }
}
