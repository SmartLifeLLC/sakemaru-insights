<?php

namespace Tests\Feature\Monitoring;

use App\Models\Monitoring\Incident;
use App\Models\Monitoring\LogEntry;
use App\Models\Monitoring\Notification;
use App\Models\Monitoring\NotificationAttempt;
use App\Monitoring\Services\IncidentQueryService;
use App\Monitoring\Services\LogQueryService;
use App\Monitoring\Services\NotificationDispatcher;
use App\Monitoring\Services\ObservationIngestor;
use App\Monitoring\Support\Cursor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/** AC-06/36/37/38/39/40 と AC-23〜26（キーセット） */
class DispatchAndPagingTest extends MonitoringTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootRegistries();
        putenv('MONITOR_SLACK_ERROR_WEBHOOK=https://hooks.slack.com/services/T/B/x');
    }

    private function dispatcher(): NotificationDispatcher
    {
        return app(NotificationDispatcher::class);
    }

    public function test_new_error_is_delivered_with_public_id_and_without_mentions_or_secrets(): void
    {
        Http::fake(['hooks.slack.com/*' => Http::response('ok', 200)]);
        app(ObservationIngestor::class)->ingestInternal([$this->event(['summary' => '<!channel> @here 障害です password=hunter2 tel 03-1234-5678'])]);
        $stats = $this->dispatcher()->dispatch();
        $this->assertSame(1, $stats['sent']);
        Http::assertSent(function ($req) {
            $text = $req['text'];

            return str_contains($text, 'MON-000001') && str_contains($text, '[華 LOCAL][乃蓮 TRADE][ERROR]') && str_contains($text, '算盤 監視')
                && ! str_contains($text, '<!channel>') && ! str_contains($text, '@here') && ! str_contains($text, 'hunter2') && ! str_contains($text, '1234-5678');
        });
        $this->assertSame('DELIVERED', Notification::sole()->status->value);
        $this->assertSame('DELIVERED', Incident::sole()->last_delivery_status);
        $this->assertSame('OPEN', Incident::sole()->workflow_status->value, 'DELIVERED だけでは ACKNOWLEDGED にならない');
    }

    public function test_429_respects_retry_after_and_keeps_notification(): void
    {
        Http::fake(['hooks.slack.com/*' => Http::sequence()->push('rate', 429, ['Retry-After' => '120'])->push('ok', 200)]);
        app(ObservationIngestor::class)->ingestInternal([$this->event()]);
        $now = CarbonImmutable::now('UTC');
        $stats = $this->dispatcher()->dispatch($now);
        $this->assertSame(1, $stats['retried']);
        $n = Notification::sole();
        $this->assertSame('RETRY_WAIT', $n->status->value);
        $this->assertGreaterThanOrEqual(119, CarbonImmutable::instance($n->available_at)->diffInSeconds($now, true));
        // Retry-After 以前は再送しない
        $this->assertSame(0, $this->dispatcher()->dispatch($now->addSeconds(60))['sent']);
        $this->assertSame(1, $this->dispatcher()->dispatch($now->addSeconds(121))['sent']);
    }

    public function test_timeout_retries_and_never_marks_delivered(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));
        app(ObservationIngestor::class)->ingestInternal([$this->event()]);
        $stats = $this->dispatcher()->dispatch();
        $this->assertSame(1, $stats['retried']);
        $this->assertSame('RETRY_WAIT', Notification::sole()->status->value);
        $this->assertSame(1, NotificationAttempt::count());
        $this->assertSame('RETRY', NotificationAttempt::sole()->result);
    }

    public function test_permanent_4xx_blocks_and_raises_self_monitoring_incident(): void
    {
        $fixed = false;
        Http::fake(['hooks.slack.com/*' => function () use (&$fixed) {
            return $fixed ? Http::response('ok', 200) : Http::response('no_service', 404);
        }]);
        app(ObservationIngestor::class)->ingestInternal([$this->event()]);
        $stats = $this->dispatcher()->dispatch();
        $this->assertSame(1, $stats['blocked']);
        $this->assertSame('BLOCKED', Notification::query()->where('incident_id', 1)->sole()->status->value);
        $this->assertTrue(Incident::query()->where('error_code', 'MONITOR_NOTIFICATION_BLOCKED')->exists(), '配送異常がエラー管理に表示される');

        // 経路が直って配送できたら、配送不可の自己監視案件は自動で復旧する（華 本番で残り続けた回帰）
        $fixed = true;
        app(ObservationIngestor::class)->ingestInternal([$this->event(['scope' => ['fp' => 'second'], 'summary' => '2 件目'])]);
        $this->dispatcher()->dispatch();
        $blocked = Incident::query()->where('error_code', 'MONITOR_NOTIFICATION_BLOCKED')->sole();
        $this->assertSame('RECOVERED', $blocked->observation_state->value);
    }

    public function test_missing_route_blocks_instead_of_pretending_delivery(): void
    {
        putenv('MONITOR_SLACK_ERROR_WEBHOOK=');
        Http::fake();
        app(ObservationIngestor::class)->ingestInternal([$this->event()]);
        $stats = $this->dispatcher()->dispatch();
        $this->assertSame(1, $stats['blocked']);
        Http::assertNothingSent();
    }

    public function test_expired_lease_is_reclaimed_and_stale_worker_cannot_complete(): void
    {
        Http::fake(['hooks.slack.com/*' => Http::response('ok', 200)]);
        app(ObservationIngestor::class)->ingestInternal([$this->event()]);
        $n = Notification::sole();
        $n->status = \App\Monitoring\Enums\NotificationStatus::SENDING;
        $n->lease_token = 'old-worker';
        $n->lease_expires_at = CarbonImmutable::now('UTC')->subMinutes(5);
        $n->save();
        $stats = $this->dispatcher()->dispatch();
        $this->assertSame(1, $stats['sent']);
        // 古いワーカーの完了 UPDATE（lease_token 不一致）は効かない
        $updated = Notification::query()->where('id', $n->id)->where('lease_token', 'old-worker')->update(['status' => 'CANCELLED']);
        $this->assertSame(0, $updated);
        $this->assertSame('DELIVERED', Notification::sole()->status->value);
    }

    public function test_storm_is_batched_without_losing_any_incident(): void
    {
        Http::fake(['hooks.slack.com/*' => Http::response('ok', 200)]);
        config()->set('monitoring.notification.rate_limit_per_minute', 50);
        $events = [];
        for ($i = 0; $i < 30; $i++) {
            $events[] = $this->event(['scope' => ['fp' => 'fp'.$i], 'error_code' => 'E'.$i]);
        }
        app(ObservationIngestor::class)->ingestInternal($events);
        $this->assertSame(30, Incident::count());
        $stats = $this->dispatcher()->dispatch();
        $this->assertSame(30, $stats['sent']);
        Http::assertSentCount(2); // 20 + 10 のまとめ配送
        $this->assertSame(30, Notification::query()->where('status', 'DELIVERED')->whereNotNull('delivery_batch_id')->count());
        $this->assertSame(2, \App\Models\Monitoring\DeliveryBatch::count());
    }

    public function test_stale_generation_recovery_notification_is_cancelled(): void
    {
        Http::fake(['hooks.slack.com/*' => Http::response('ok', 200)]);
        app(ObservationIngestor::class)->ingestInternal([$this->event()]);
        $i = Incident::sole();
        $n = Notification::query()->create(['incident_id' => $i->id, 'generation' => 0, 'notification_key' => 'stale', 'kind' => 'RECOVERY', 'route_key' => 'error_channel', 'severity' => 'ERROR', 'status' => 'PENDING', 'available_at' => now('UTC'), 'created_at' => now('UTC')]);
        $stats = $this->dispatcher()->dispatch();
        $this->assertSame(1, $stats['cancelled']);
        $this->assertSame('CANCELLED', $n->fresh()->status->value);
    }

    public function test_keyset_paging_is_anchored_and_cursor_is_signed(): void
    {
        $events = [];
        for ($i = 0; $i < 25; $i++) {
            $events[] = $this->event(['severity' => 'INFO', 'error_code' => 'LOG_INFO', 'summary' => "row {$i}"]);
        }
        app(ObservationIngestor::class)->ingestInternal($events);
        $svc = app(LogQueryService::class);
        $p1 = $svc->page([], null, 10);
        $this->assertCount(10, $p1->items);
        $this->assertNotNull($p1->nextCursor);
        $this->assertNull($p1->prevCursor);
        $firstIds = array_map(fn ($r) => $r->id, $p1->items);

        // 閲覧中の新着は途中に混ざらない
        app(ObservationIngestor::class)->ingestInternal([$this->event(['severity' => 'INFO', 'error_code' => 'LOG_INFO', 'summary' => 'new'])]);
        $p2 = $svc->page([], $p1->nextCursor, 10);
        $this->assertCount(10, $p2->items);
        $this->assertTrue($p2->hasNewer);
        $this->assertEmpty(array_intersect($firstIds, array_map(fn ($r) => $r->id, $p2->items)));
        $this->assertLessThan(min($firstIds), max(array_map(fn ($r) => $r->id, $p2->items)));
        $p3 = $svc->page([], $p2->nextCursor, 10);
        $this->assertCount(5, $p3->items);
        $this->assertNull($p3->nextCursor);
        // 戻る
        $back = $svc->page([], $p3->prevCursor, 10);
        $this->assertSame(array_map(fn ($r) => $r->id, $p2->items), array_map(fn ($r) => $r->id, $back->items));
        // 境界行が消えても次の取得が可能
        LogEntry::query()->whereIn('id', array_slice($firstIds, -1))->delete();
        $this->assertCount(10, $svc->page([], $p1->nextCursor, 10)->items);

        // 改ざん・期限切れ・別フィルタのカーソルを拒否
        $this->expectException(\InvalidArgumentException::class);
        $svc->page([], substr($p1->nextCursor, 0, -2).'zz', 10);
    }

    public function test_cursor_rejects_filter_change_and_expiry(): void
    {
        $c = new Cursor(100, 50, 'hashA', 10, time() - 10, 'next', 'logs');
        $token = $c->encode();
        $this->assertSame(50, Cursor::decode($token, 'hashA', 'logs', 1800, time())->lastId);
        try {
            Cursor::decode($token, 'hashB', 'logs', 1800, time());
            $this->fail();
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('cursor_filter_changed', $e->getMessage());
        }
        try {
            Cursor::decode($token, 'hashA', 'incidents', 1800, time());
            $this->fail();
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('cursor_scope', $e->getMessage());
        }
        try {
            Cursor::decode($token, 'hashA', 'logs', 5, time());
            $this->fail();
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('cursor_expired', $e->getMessage());
        }
    }

    public function test_window_limits_are_enforced_instead_of_silent_full_scan(): void
    {
        $svc = app(LogQueryService::class);
        $this->expectException(\InvalidArgumentException::class);
        $svc->page(['from' => now()->subDays(60)->toIso8601String(), 'to' => now()->toIso8601String()], null, 10);
    }

    public function test_incident_query_tabs(): void
    {
        app(ObservationIngestor::class)->ingestInternal([$this->event(), $this->event(['severity' => 'CRITICAL', 'scope' => ['fp' => 'crit']])]);
        $svc = app(IncidentQueryService::class);
        $this->assertCount(2, $svc->page(['tab' => 'unacknowledged'], null, 10)->items);
        $this->assertCount(1, $svc->page(['tab' => 'critical'], null, 10)->items);
        $this->assertCount(0, $svc->page(['tab' => 'resolved'], null, 10)->items);
        $this->assertCount(1, $svc->page(['tab' => 'all', 'search' => 'MON-000002'], null, 10)->items);
    }

    /** 華 本番の初回リリースで、未送信通知が 5 分を超えたときに monitor.notification が enum→文字列変換の例外で UNKNOWN になった回帰テスト。 */
    public function test_notification_backlog_check_reports_stale_without_exception(): void
    {
        app(ObservationIngestor::class)->ingestInternal([$this->event()]);
        $i = Incident::sole();
        Notification::query()->delete();
        Notification::query()->create(['incident_id' => $i->id, 'generation' => 1, 'notification_key' => 'old', 'kind' => 'NEW', 'route_key' => 'error_channel', 'severity' => 'ERROR', 'status' => 'RETRY_WAIT', 'last_error_code' => 'http_500', 'available_at' => now('UTC')->subMinutes(6), 'created_at' => now('UTC')->subMinutes(11)]);
        $check = new \App\Monitoring\Checks\Monitor\NotificationBacklogCheck;
        $eval = fn () => collect($check->evaluate(new \App\Monitoring\Services\CheckContext(\App\Monitoring\Services\SourceReader::fromConfig(), CarbonImmutable::now('UTC'), $check->definition()->defaultConfig))->observations)->first(fn ($o) => $o->scope === ['queue' => 'pending']);
        $stale = $eval();
        $this->assertSame('VIOLATION', $stale->outcome->value);
        $this->assertStringContainsString('RETRY_WAIT / http_500', $stale->summary);

        // 電話の猶予（作成から 5 分待って発信）や再試行の待機は「未送信」に数えない（MON-000018 第 5 世代の誤検知）
        Notification::query()->delete();
        Notification::query()->create(['incident_id' => $i->id, 'generation' => 1, 'notification_key' => 'phone', 'kind' => 'NEW', 'route_key' => 'phone', 'severity' => 'ERROR', 'status' => 'PENDING', 'available_at' => now('UTC')->addSeconds(15), 'created_at' => now('UTC')->subMinutes(5)->subSeconds(1)]);
        $this->assertSame('HEALTHY', $eval()->outcome->value, '送信可能時刻がまだ来ていない通知は遅延ではない');
        Notification::query()->create(['incident_id' => $i->id, 'generation' => 1, 'notification_key' => 'due', 'kind' => 'NEW', 'route_key' => 'phone', 'severity' => 'ERROR', 'status' => 'SENDING', 'available_at' => now('UTC')->subMinutes(2), 'created_at' => now('UTC')->subMinutes(8)]);
        $this->assertSame('HEALTHY', $eval()->outcome->value, '送信可能から 2 分なら正常（作成からは 8 分）');
        Notification::query()->where('notification_key', 'due')->update(['available_at' => now('UTC')->subMinutes(6)]);
        $this->assertSame('VIOLATION', $eval()->outcome->value, '送信可能から 6 分で遅延');
    }

    /** 経路を設定した後、過去の配送不可（BLOCKED）が残っていても monitor.notification は直近 1 時間だけを見る。 */
    public function test_notification_backlog_check_ignores_old_blocked_notifications(): void
    {
        app(ObservationIngestor::class)->ingestInternal([$this->event()]);
        $i = Incident::sole();
        Notification::query()->delete();
        Notification::query()->create(['incident_id' => $i->id, 'generation' => 1, 'notification_key' => 'old-blocked', 'kind' => 'NEW', 'route_key' => 'error_channel', 'severity' => 'ERROR', 'status' => 'BLOCKED', 'last_error_code' => 'route_not_configured', 'available_at' => now('UTC')->subHours(3), 'created_at' => now('UTC')->subHours(3)]);
        $check = new \App\Monitoring\Checks\Monitor\NotificationBacklogCheck;
        $ctx = fn () => new \App\Monitoring\Services\CheckContext(\App\Monitoring\Services\SourceReader::fromConfig(), CarbonImmutable::now('UTC'), $check->definition()->defaultConfig);
        $blocked = fn ($r) => collect($r->observations)->first(fn ($o) => $o->scope === ['queue' => 'blocked']);
        $this->assertSame('HEALTHY', $blocked($check->evaluate($ctx()))->outcome->value, '3 時間前の配送不可は見ない');
        // お知らせ（ベル）も同じ窓で数える。古い BLOCKED だけならお知らせに出ない
        $texts = fn () => array_column(app(\App\Monitoring\Services\MonitorHealthService::class)->banners(), 'text');
        $this->assertEmpty(array_filter($texts(), fn ($t) => str_contains($t, 'BLOCKED')), '古い配送不可はお知らせに出ない');
        Notification::query()->create(['incident_id' => $i->id, 'generation' => 1, 'notification_key' => 'new-blocked', 'kind' => 'NEW', 'route_key' => 'error_channel', 'severity' => 'ERROR', 'status' => 'BLOCKED', 'last_error_code' => 'route_not_configured', 'available_at' => now('UTC'), 'created_at' => now('UTC')]);
        $this->assertSame('VIOLATION', $blocked($check->evaluate($ctx()))->outcome->value);
        $this->assertNotEmpty(array_filter($texts(), fn ($t) => str_contains($t, 'BLOCKED）になった通知が 1 件')));
    }

    /** 通知の詳細リンクは MONITOR_PUBLIC_BASE_URL を優先する（本番で APP_URL が別ホストだった回帰） */
    public function test_slack_links_use_public_base_url(): void
    {
        config()->set('app.url', 'https://lw-hana.net');
        config()->set('monitoring.phone.public_base_url', 'https://insights.lw-hana.net');
        $this->assertSame('https://insights.lw-hana.net/monitor/incidents/MON-000001', \App\Monitoring\Services\SlackMessageBuilder::publicUrl('/monitor/incidents/MON-000001'));
        config()->set('monitoring.phone.public_base_url', null);
        $this->assertSame('https://lw-hana.net/monitor/incidents', \App\Monitoring\Services\SlackMessageBuilder::publicUrl('monitor/incidents'));
    }

    /** 経路の復旧後に溜まっていた古い通知は送らない: 解決・終了済みの案件 → incident_closed、作成から 6 時間超 → expired（華 本番で深夜の通知が昼にまとめて届いた回帰） */
    public function test_stale_notifications_are_cancelled_instead_of_sent_late(): void
    {
        Http::fake(['hooks.slack.com/*' => Http::response('ok', 200)]);
        app(ObservationIngestor::class)->ingestInternal([
            $this->event(['scope' => ['fp' => 'old'], 'summary' => '古い']),
            $this->event(['scope' => ['fp' => 'closed'], 'summary' => '閉じた']),
            $this->event(['scope' => ['fp' => 'fresh'], 'summary' => '新しい']),
        ]);
        $old = Incident::query()->where('scope_json->fp', 'old')->sole();
        $closed = Incident::query()->where('scope_json->fp', 'closed')->sole();
        Notification::query()->where('incident_id', $old->id)->update(['created_at' => now('UTC')->subHours(7)]);
        $closed->forceFill(['workflow_status' => 'CLOSED', 'observation_state' => 'RECOVERED', 'closed_at' => now('UTC')])->save();
        $stats = $this->dispatcher()->dispatch();
        $this->assertSame(1, $stats['sent']);
        $this->assertSame(2, $stats['cancelled']);
        $this->assertSame('expired', Notification::query()->where('incident_id', $old->id)->sole()->cancel_reason);
        $this->assertSame('incident_closed', Notification::query()->where('incident_id', $closed->id)->sole()->cancel_reason);
    }
}
