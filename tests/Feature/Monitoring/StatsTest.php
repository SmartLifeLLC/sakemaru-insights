<?php

namespace Tests\Feature\Monitoring;

use App\Models\Monitoring\Counter;
use App\Monitoring\Services\CounterService;
use App\Monitoring\Services\ObservationIngestor;
use App\Monitoring\Services\SiteRegistry;
use App\Monitoring\Services\StatsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** v2 V-080〜086: 統計基盤 */
class StatsTest extends MonitoringTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootRegistries();
    }

    private function counter(int $siteId, CarbonImmutable $hour, array $counts, string $key = CounterService::KEY_SEVERITY_HOURLY): void
    {
        Counter::query()->create(['site_id' => $siteId, 'counter_key' => $key, 'bucket_start' => $hour->utc()->startOfHour(), 'counts_json' => $counts, 'calculated_at' => now('UTC')]);
    }

    public function test_windows_have_equal_length_and_previous_is_adjacent(): void
    {
        $now = CarbonImmutable::parse('2026-11-03 10:20:00', 'UTC');
        foreach (['24h' => 24, '7d' => 168, '30d' => 720] as $p => $h) {
            [$f, $t] = StatsService::window($p, $now);
            [$pf, $pt] = StatsService::window($p, $now, 1);
            $this->assertSame($h * 3600, $t->getTimestamp() - $f->getTimestamp());
            $this->assertSame($h * 3600, $pt->getTimestamp() - $pf->getTimestamp());
            $this->assertTrue($pt->equalTo($f), $p.' の前期間は直前');
            $this->assertSame('2026-11-03 11:00:00', $t->format('Y-m-d H:i:s'), '現在の時間を含む');
        }
    }

    public function test_kpis_healthy_rate_excludes_not_due_and_compares_previous(): void
    {
        $trade = app(SiteRegistry::class)->site('TRADE')->id;
        $now = CarbonImmutable::parse('2026-11-03 10:20:00', 'UTC');
        $this->counter($trade, $now->subHours(2), ['INFO' => 90, 'WARNING' => 5, 'ERROR' => 5, 'outcome_HEALTHY' => 80, 'outcome_WARNING' => 5, 'outcome_VIOLATION' => 5, 'outcome_UNKNOWN' => 10, 'outcome_NOT_DUE' => 500, 'incident_new' => 2, 'deduplicated' => 3]);
        $this->counter($trade, $now->subHours(30), ['ERROR' => 1, 'outcome_HEALTHY' => 9, 'outcome_VIOLATION' => 1]);
        $this->counter($trade, $now->subHour(), ['queued' => 4, 'delivered' => 3, 'blocked' => 1], CounterService::KEY_NOTIFICATION_HOURLY);
        $k = app(StatsService::class)->kpis('24h', null, $now);
        $this->assertSame(80.0, $k['current']['healthy_rate'], '80 / (80+5+5+10)。NOT_DUE は分母に入れない');
        $this->assertSame(90.0, $k['previous']['healthy_rate']);
        $this->assertSame(-10.0, $k['delta']['healthy_rate']);
        $this->assertSame(75.0, $k['current']['delivery_rate']);
        $flow = app(StatsService::class)->flow('24h', null, $now);
        $this->assertSame(['received' => 100, 'anomalies' => 10, 'incidents' => 2, 'deduplicated' => 3, 'queued' => 4, 'delivered' => 3, 'failed' => 1], $flow);
        $this->assertNull(app(StatsService::class)->kpis('24h', [999], $now)['current']['healthy_rate'], '判定なしは null（0% と表示しない）');
    }

    public function test_heatmap_uses_jst_weekday_and_two_hour_slots(): void
    {
        $trade = app(SiteRegistry::class)->site('TRADE')->id;
        // 2026-11-02 (月) 23:30 JST = 14:30 UTC → 月曜・22–24 時（slot 11）
        $this->counter($trade, CarbonImmutable::parse('2026-11-02 23:30', 'Asia/Tokyo'), ['ERROR' => 3, 'WARNING' => 1, 'INFO' => 50]);
        // 2026-11-03 (火) 00:10 JST = 前日 15:10 UTC → 火曜・slot 0
        $this->counter($trade, CarbonImmutable::parse('2026-11-03 00:10', 'Asia/Tokyo'), ['CRITICAL' => 2]);
        $h = app(StatsService::class)->heatmap('30d', null, CarbonImmutable::parse('2026-11-03 12:00', 'Asia/Tokyo'));
        $this->assertSame(4, $h['cells'][1][11]);
        $this->assertSame(2, $h['cells'][2][0]);
        $this->assertSame(4, $h['max']);
    }

    public function test_service_bars_show_worst_state_and_none_when_unmonitored(): void
    {
        $sites = app(SiteRegistry::class);
        $now = CarbonImmutable::parse('2026-11-03 10:20:00', 'UTC');
        $this->counter($sites->site('WMS')->id, $now->subHours(1), ['outcome_HEALTHY' => 10, 'outcome_VIOLATION' => 1, 'ERROR' => 1]);
        $this->counter($sites->site('WMS')->id, $now, ['outcome_HEALTHY' => 10]);
        $bars = app(StatsService::class)->serviceBars($now);
        $wms = $bars[$sites->site('WMS')->id];
        $this->assertCount(24, $wms);
        $this->assertSame('violation', $wms[22]['state']);
        $this->assertSame('healthy', $wms[23]['state']);
        $this->assertSame('none', $wms[0]['state']);
        $this->assertSame('none', $bars[$sites->site('SEARCH')->id][23]['state'], '判定なしは灰色');
    }

    public function test_ingest_flushes_counters_once_per_batch_and_dashboard_queries_touch_only_monitor_tables(): void
    {
        $events = [];
        for ($i = 0; $i < 10; $i++) {
            $events[] = $this->event(['severity' => 'INFO', 'error_code' => 'LOG_INFO', 'summary' => 'r'.$i]);
        }
        $events[] = $this->event();
        $events[] = $this->event(); // 同じ指紋 → 集約
        app(ObservationIngestor::class)->ingestInternal($events);
        $c = Counter::query()->where('counter_key', 'severity_hourly')->sole();
        $this->assertSame(10, (int) $c->counts_json['INFO']);
        $this->assertSame(2, (int) $c->counts_json['ERROR']);
        $this->assertSame(1, (int) $c->counts_json['incident_new']);
        $this->assertSame(1, (int) $c->counts_json['deduplicated']);

        $queries = [];
        $source = 0;
        DB::listen(function ($q) use (&$queries, &$source) {
            if ($q->connectionName === 'monitor_source') {
                $source++;
            } elseif ($q->connectionName === 'monitor_store') {
                $queries[] = $q->sql;
            }
        });
        $svc = app(StatsService::class);
        $svc->kpis('24h');
        $svc->flow('24h');
        $svc->series('7d');
        $svc->heatmap('30d');
        $svc->serviceBars();
        $svc->bySite('24h');
        $this->assertSame(0, $source, '業務DB SQL なし');
        $this->assertLessThanOrEqual(8, count($queries));
        foreach ($queries as $sql) {
            $this->assertStringNotContainsString('count(', strtolower($sql), '全件 COUNT をしない');
            $this->assertMatchesRegularExpression('/monitor_(counters|sites)/', $sql);
        }
    }
}
