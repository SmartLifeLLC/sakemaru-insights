<?php

namespace Tests\Feature\Monitoring;

use App\Models\Monitoring\Advisory;
use App\Models\Monitoring\AdvisoryFetch;
use App\Monitoring\Advisories\AdvisoryFetcher;
use App\Monitoring\Advisories\AdvisoryService;
use App\Monitoring\Advisories\Environment;
use App\Monitoring\Advisories\VersionRange;
use App\Monitoring\Checks\Monitor\AdvisoriesCheck;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\SourceReader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/** 脆弱性情報の収集（6 情報源・導入版照合・KEV/EPSS 付与）、画面、監視項目、ダッシュボードの 1 行。 */
class AdvisoriesTest extends MonitoringTestCase
{
    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/advisories/'.$name));
    }

    private function fakeAll(array $overrides = []): void
    {
        Http::fake(array_merge([
            // NVD は製品（CPE）ごとに問い合わせる。PHP の問い合わせだけ結果を返す
            'services.nvd.nist.gov/*' => fn ($req) => str_contains(urldecode($req->url()), 'cpe:2.3:a:php:php') ? Http::response($this->fixture('nvd.json'), 200) : Http::response('{"vulnerabilities":[]}', 200),
            'api.github.com/advisories*' => Http::response($this->fixture('github.json'), 200),
            'ubuntu.com/security/notices.json*' => Http::response($this->fixture('ubuntu.json'), 200),
            'www.cisa.gov/*' => Http::response($this->fixture('kev.json'), 200),
            'aws.amazon.com/*' => Http::response($this->fixture('aws.xml'), 200, ['Content-Type' => 'application/rss+xml']),
            'jvndb.jvn.jp/*' => Http::response($this->fixture('jvn.xml'), 200, ['Content-Type' => 'application/xml']),
            'api.first.org/*' => Http::response($this->fixture('epss.json'), 200),
        ], $overrides));
        // 導入環境はテスト用に固定（composer.lock・dpkg は読まない）
        $this->app->instance(Environment::class, new Environment(
            ['laravel/framework' => '12.30.1', 'livewire/livewire' => '3.6.4'],
            ['apache2' => '2.4.58-1ubuntu8.5', 'apache2-bin' => '2.4.58-1ubuntu8.5'],
            'noble',
        ));
    }

    public function test_version_range(): void
    {
        $this->assertTrue(VersionRange::contains('>= 9.0.0, < 9.52.17', '9.52.16'));
        $this->assertFalse(VersionRange::contains('>= 9.0.0, < 9.52.17', '9.52.17'));
        $this->assertTrue(VersionRange::contains('< 10.48.23', 'v10.48.0'));
        $this->assertFalse(VersionRange::contains('= 1.2.3', '1.2.4'));
        $this->assertNull(VersionRange::contains('weird', '1.0.0'));
    }

    public function test_fetch_collects_from_all_sources_and_matches_installed_versions(): void
    {
        $this->fakeAll();
        $now = CarbonImmutable::parse('2026-11-05 03:00:00', 'UTC');
        $res = app(AdvisoryFetcher::class)->run($now);
        $this->assertSame(['OK', 'OK', 'OK', 'OK', 'OK', 'OK'], array_column($res, 'status'), json_encode($res, JSON_UNESCAPED_UNICODE));

        // NVD: PHP の CVE 2 件。導入 PHP（8.x）が 8.0.0〜99.0.0 の範囲に入る方だけ installed_affected
        $php = Advisory::query()->where('source', 'nvd')->where('external_id', 'CVE-2026-10001')->sole();
        $this->assertSame('CRITICAL', $php->severity);
        $this->assertSame(9.8, $php->cvss);
        $this->assertTrue($php->installed_affected);
        $this->assertSame(['php'], $php->components_json);
        $this->assertFalse(Advisory::query()->where('external_id', 'CVE-2026-10002')->sole()->installed_affected, '5.x のみの問題は導入版に影響しない');

        // GitHub: 導入パッケージだけ保存。laravel/framework は範囲内、livewire は修正済みの版
        $gh = Advisory::query()->where('source', 'github')->orderBy('external_id')->get();
        $this->assertSame(['GHSA-test-0001', 'GHSA-test-0003'], $gh->pluck('external_id')->all(), '導入していないパッケージ（GHSA-test-0002）は保存しない');
        $this->assertTrue($gh[0]->installed_affected);
        $this->assertSame('HIGH', $gh[0]->severity);
        $this->assertSame(['laravel'], $gh[0]->components_json);
        $this->assertFalse($gh[1]->installed_affected);
        $this->assertSame('MEDIUM', $gh[1]->severity, 'moderate → MEDIUM');

        // Ubuntu: apache2 の USN だけ（導入版 8.5 < 修正版 8.9 → 影響）。古い通知と無関係パッケージは除外
        $usn = Advisory::query()->where('source', 'ubuntu')->get();
        $this->assertSame(['USN-9001-1'], $usn->pluck('external_id')->all());
        $this->assertTrue($usn[0]->installed_affected);
        $this->assertSame('CVE-2026-30001', $usn[0]->cve_id);
        $this->assertContains('apache', $usn[0]->components_json);

        // KEV: 対象製品で期間内のものだけ。NVD の CVE-2026-10001 にも kev=true が付く
        $this->assertSame(['CVE-2026-10001', 'CVE-2026-40001'], Advisory::query()->where('source', 'kev')->orderBy('external_id')->pluck('external_id')->all());
        $this->assertTrue($php->fresh()->kev, 'NVD 由来の行にも KEV の印');
        $this->assertTrue(Advisory::query()->where('source', 'kev')->where('external_id', 'CVE-2026-40001')->sole()->kev);

        // AWS: 期間内の 1 件。JVN: 1 件（CVSS 8.1 → HIGH、CVE 付き）
        $this->assertSame(1, Advisory::query()->where('source', 'aws')->count());
        $aws = Advisory::query()->where('source', 'aws')->sole();
        $this->assertSame('CVE-2026-50001', $aws->cve_id);
        $this->assertSame('2026-162-AWS', $aws->external_id, 'ID は速報番号');
        $this->assertSame('https://aws.amazon.com/security/security-bulletins/2026-162-aws/', $aws->url, 'リンクは公開ページ');
        $jvn = Advisory::query()->where('source', 'jvn')->sole();
        $this->assertSame('JVNDB-2026-000001', $jvn->external_id);
        $this->assertSame('HIGH', $jvn->severity);
        $this->assertSame('CVE-2026-40001', $jvn->cve_id);
        $this->assertTrue($jvn->kev, 'JVN の行も同じ CVE が KEV にあるので印が付く');

        // EPSS: 新規 CVE に付く
        $this->assertEqualsWithDelta(0.91234, $php->fresh()->epss, 0.00001);

        // 取得履歴と、再実行しても二重登録にならない（状態は保持）
        $this->assertSame(6, AdvisoryFetch::query()->where('status', 'OK')->count());
        $total = Advisory::query()->count();
        app(AdvisoryService::class)->setStatus($php, 'ACKNOWLEDGED', ['id' => 1, 'name' => 't'], null);
        $res2 = app(AdvisoryFetcher::class)->run($now->addHours(6));
        $this->assertSame($total, Advisory::query()->count());
        $this->assertSame(0, array_sum(array_column($res2, 'new')));
        $this->assertSame('ACKNOWLEDGED', $php->fresh()->status, '再取得で状態は変わらない');

        // 秘密値（API キー）を外部へ送るヘッダは設定時だけ。未設定なら apiKey ヘッダ無し
        Http::assertSent(fn ($req) => str_contains($req->url(), 'services.nvd.nist.gov') && ! $req->hasHeader('apiKey'));
    }

    public function test_failed_source_is_recorded_and_others_continue(): void
    {
        $this->fakeAll(['services.nvd.nist.gov/*' => Http::response('rate limited', 503)]);
        $res = app(AdvisoryFetcher::class)->run(CarbonImmutable::parse('2026-11-05 03:00:00', 'UTC'));
        $this->assertSame('FAILED', $res['nvd']['status']);
        $this->assertStringContainsString('HTTP 503', $res['nvd']['error']);
        $this->assertSame('OK', $res['github']['status']);
        $this->assertSame(1, AdvisoryFetch::query()->where('source', 'nvd')->where('status', 'FAILED')->count());
        $this->assertGreaterThan(0, Advisory::query()->count());
    }

    public function test_command_runs_only_on_owner_node(): void
    {
        $this->fakeAll();
        config()->set('monitoring.deployment.check_owner_node', 'other-node');
        $this->artisan('monitor:fetch-advisories')->expectsOutputToContain('owner')->assertSuccessful();
        $this->assertSame(0, Advisory::query()->count());
        $this->artisan('monitor:fetch-advisories', ['--ignore-owner' => true, '--source' => ['kev']])->expectsOutputToContain('CISA KEV')->assertSuccessful();
        $this->assertSame(2, Advisory::query()->count());
    }

    public function test_check_warns_on_urgent_unreviewed_and_recovers_after_review(): void
    {
        $this->fakeAll();
        $now = CarbonImmutable::parse('2026-11-05 03:00:00', 'UTC');
        $check = new AdvisoriesCheck;
        $ctx = fn (CarbonImmutable $t) => new CheckContext(SourceReader::fromConfig(), $t, $check->definition()->defaultConfig);
        $byScope = fn ($r) => collect($r->observations)->keyBy(fn ($o) => $o->scope['queue'])->map(fn ($o) => $o->outcome->value)->all();

        $this->assertSame(['advisories' => 'HEALTHY', 'advisories_fetch' => 'UNKNOWN'], $byScope($check->evaluate($ctx($now))), '未取得は UNKNOWN');
        app(AdvisoryFetcher::class)->run($now);
        $r = $check->evaluate($ctx($now->addMinutes(5)));
        $this->assertSame(['advisories' => 'WARNING', 'advisories_fetch' => 'HEALTHY'], $byScope($r));
        $this->assertSame('ADVISORY_UNREVIEWED', $r->observations[0]->errorCode);

        // 要対応（重大・KEV・導入版が影響）をすべて確認すると復旧。要対応でない新規が残っていても正常
        $svc = app(AdvisoryService::class);
        foreach (Advisory::query()->where('status', 'NEW')->where(fn ($q) => $q->where('severity', 'CRITICAL')->orWhere('kev', true)->orWhere('installed_affected', true))->get() as $a) {
            $svc->setStatus($a, 'ACKNOWLEDGED', ['id' => 1, 'name' => 't'], null);
        }
        $this->assertGreaterThan(0, Advisory::query()->where('status', 'NEW')->count());
        $this->assertSame('HEALTHY', $byScope($check->evaluate($ctx($now->addMinutes(10))))['advisories']);
        // 取得が 30 時間止まると WARNING
        $this->assertSame('WARNING', $byScope($check->evaluate($ctx($now->addHours(31))))['advisories_fetch']);
        // 対象外・対応済みには理由が必要
        $this->expectException(\InvalidArgumentException::class);
        $svc->setStatus(Advisory::query()->first(), 'NOT_APPLICABLE', ['id' => 1, 'name' => 't'], '');
    }

    public function test_page_and_dashboard_line(): void
    {
        $this->bootRegistries();
        $this->actingAs($this->user());
        // 未取得
        $this->get('/monitor')->assertSee('脆弱性情報はまだ取得されていません');
        $this->get('/monitor/advisories')->assertOk()->assertSee('脆弱性情報')->assertSee('まだ脆弱性情報がありません');

        $this->fakeAll();
        app(AdvisoryFetcher::class)->run(CarbonImmutable::now('UTC'));
        $this->get('/monitor')->assertSee('脆弱性報告が')->assertSee('悪用確認')->assertSee('adv-strip level-error', false);
        $html = $this->get('/monitor/advisories?tab=urgent')->assertOk()->getContent();
        $this->assertStringContainsString('CVE-2026-10001', $html);
        $this->assertStringContainsString('導入版が影響', $html);
        $this->assertStringNotContainsString('GHSA-test-0003', $html, '要対応タブに修正済みの版の情報は出ない');
        $this->assertStringContainsString('GHSA-test-0003', $this->get('/monitor/advisories?tab=new&source=github')->getContent());

        // 確認済みにする → 新規から消える。状態変更モーダル（対象外・理由あり）
        $php = Advisory::query()->where('external_id', 'CVE-2026-10001')->where('source', 'nvd')->sole();
        Livewire::test(\App\Filament\Monitor\Pages\Advisories::class)->call('acknowledge', $php->id);
        $this->assertSame('ACKNOWLEDGED', $php->fresh()->status);
        $c = Livewire::test(\App\Filament\Monitor\Pages\Advisories::class, ['open' => $php->id]);
        $c->callAction('status', ['status' => 'NOT_APPLICABLE', 'note' => '該当機能は使用していない'], ['id' => $php->id])->assertHasNoActionErrors();
        $this->assertSame('NOT_APPLICABLE', $php->fresh()->status);
        $this->assertSame('該当機能は使用していない', $php->fresh()->status_note);
        $this->assertTrue(\App\Models\Monitoring\AuditEvent::query()->where('action', 'advisory.status')->exists());

        // 全部確認すると「新規の脆弱性情報はありません」
        foreach (Advisory::query()->where('status', 'NEW')->get() as $a) {
            app(AdvisoryService::class)->setStatus($a, 'ACKNOWLEDGED', ['id' => 1, 'name' => 't'], null);
        }
        $this->get('/monitor')->assertSee('現在、新規の脆弱性情報はありません')->assertSee('adv-strip level-ok', false);
    }
}
