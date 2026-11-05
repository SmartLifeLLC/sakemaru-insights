<?php

namespace Tests\Feature\Monitoring;

use App\Models\Monitoring\Node;
use App\Monitoring\Services\ObservationIngestor;

/** v2 V-090〜109: /monitor パネルの各画面 */
class PagesTest extends MonitoringTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->bootRegistries();
    }

    private function seedData(): void
    {
        app(ObservationIngestor::class)->ingestInternal([
            $this->event(),
            $this->event(['severity' => 'CRITICAL', 'scope' => ['fp' => 'crit'], 'summary' => '印刷サービスに接続できません']),
            $this->event(['severity' => 'INFO', 'error_code' => 'LOG_INFO', 'summary' => '定期処理 完了']),
        ]);
        Node::query()->create(['node_code' => 'web1', 'display_name' => 'Web 1 号機', 'role' => 'web', 'enabled' => true, 'registered_from' => 'env', 'last_heartbeat_at' => now('UTC'),
            'os_info_json' => ['cores' => 2, 'mem_total_gb' => 8, 'kernel' => '6.8'],
            'latest_json' => ['ts' => now()->getTimestamp(), 'cores' => 2, 'current' => ['cpu' => 42.5, 'mem_used_pct' => 61.0, 'disk_used_pct_max' => 40.0, 'load1' => 0.8, 'iowait' => 1.0],
                'disks' => [['mount' => '/', 'fstype' => 'ext4', 'size_gb' => 100, 'free_gb' => 60, 'used_pct' => 40.0, 'inode_used_pct' => 3.0]],
                'recent' => ['t' => [now()->getTimestamp() - 30, now()->getTimestamp()], 'cpu' => [40, 42.5], 'iowait' => [1, 1], 'load1' => [0.8, 0.8], 'mem_available_pct' => [39, 39], 'swap_used_pct' => [0, 0]]]]);
    }

    public function test_all_monitor_pages_render_with_japanese_names_and_no_sample_values(): void
    {
        $this->seedData();
        $user = $this->user();
        $pages = ['/monitor', '/monitor/services', '/monitor/analytics', '/monitor/incidents', '/monitor/incidents/MON-000001', '/monitor/logs',
            '/monitor/rules', '/monitor/rules?tab=rules', '/monitor/rules?tab=test', '/monitor/rules?tab=nodes', '/monitor/rules?tab=history',
            '/monitor/notifications', '/monitor/runbooks', '/monitor/runbooks?key=RB-12', '/monitor/nodes', '/monitor/nodes/web1', '/monitor/nodes/web1?range=7d', '/monitor/services?site=WMS'];
        foreach ($pages as $path) {
            $res = $this->actingAs($user)->get($path);
            $res->assertStatus(200);
            $res->assertSee('華 LOCAL');
            $res->assertSee('算盤');
            $res->assertDontSee('サンプル');
            $res->assertDontSee('酒丸（基幹）');
        }
        $this->actingAs($user)->get('/monitor')->assertSee('重大な障害')->assertSee('印刷サービスに接続できません')->assertSee('Web 1 号機');
        $this->actingAs($user)->get('/monitor/services')->assertSee('蔵 WMS')->assertSee('勘定衆 CORE')->assertSee('千里眼 SEARCH')->assertSee('飛脚 DELIVERY');
        $this->actingAs($user)->get('/monitor/incidents/MON-000001')->assertSee('MON-000001')->assertSee('SQLSTATE');
        $this->actingAs($user)->get('/monitor/incidents/MON-999999')->assertStatus(404);
        $this->actingAs($user)->get('/monitor/nodes/nothing')->assertStatus(404);
        $this->actingAs($user)->get('/monitor/runbooks?key=RB-17')->assertSee('飛脚: 配送端末の同期');
    }

    public function test_pages_require_view_permission_and_secrets_require_delivery_admin(): void
    {
        $user = $this->user();
        config()->set('monitoring.permissions', ['monitor.view' => ['*'], 'monitor.delivery_admin' => ['nobody@example.com']]);
        app(\App\Monitoring\Services\SecretStore::class)->save(['smtp.password' => 'Sup3rS3cret!'], ['id' => 1, 'name' => 'x'], '設定');
        $res = $this->actingAs($user)->get('/monitor/notifications');
        $res->assertOk()->assertDontSee('接続情報を編集')->assertDontSee('ret!')->assertSee('変更は配送管理者のみ');
        config()->set('monitoring.permissions', ['monitor.view' => ['*'], 'monitor.delivery_admin' => ['*']]);
        $res = $this->actingAs($user)->get('/monitor/notifications');
        $res->assertSee('接続情報を編集')->assertSee('••••••••ret!')->assertDontSee('Sup3rS3cret!');
        config()->set('monitoring.permissions', ['monitor.view' => ['someone-else@example.com']]);
        $this->actingAs($user)->get('/monitor')->assertStatus(403);
        $this->actingAs($user)->get('/monitor/logs')->assertStatus(403);
    }

    public function test_old_admin_urls_redirect_to_monitor_panel(): void
    {
        $this->seedData();
        $user = $this->user();
        $this->actingAs($user)->get('/admin/monitor')->assertRedirect('/monitor');
        $this->actingAs($user)->get('/admin/monitor/incidents/MON-000001')->assertRedirect('/monitor/incidents/MON-000001');
        $this->actingAs($user)->get('/admin/monitor/logs?site=TRADE')->assertRedirect('/monitor/logs?site=TRADE');
    }

    public function test_guest_is_sent_to_login(): void
    {
        $this->get('/monitor')->assertRedirect('/monitor/login');
    }

    public function test_login_page_shows_moribito_branding_and_form(): void
    {
        $this->withoutVite();
        $this->get('/monitor/login')->assertOk()
            ->assertSee('images/moribito/wordmark.svg')
            ->assertSee('おかえりなさい')
            ->assertSee('ログイン状態を保持')
            ->assertSee('images/moribito/shield.svg')
            ->assertSee('images/moribito/login-bg.jpg')
            ->assertSee('images/moribito/favicon.png')
            ->assertSee('wire:submit="authenticate"', false);
    }

    public function test_ingest_api_rejects_unsigned_requests(): void
    {
        $this->postJson('/internal/monitor/events', ['events' => []])->assertStatus(401);
    }

    public function test_ingest_api_accepts_signed_batch_and_returns_duplicates_and_conflicts(): void
    {
        $uuid = '7c7a2f6e-1111-4e8f-9b7e-000000000001';
        config()->set('monitoring.collectors', [[
            'collector_uuid' => $uuid, 'application_code' => 'TRADE', 'node_code' => 'web1', 'secret_env' => 'MONITOR_TEST_SECRET', 'log_files' => [],
        ]]);
        putenv('MONITOR_TEST_SECRET=topsecret');
        $this->bootRegistries();
        $ev = $this->event();
        $body = json_encode(['collector_uuid' => $uuid, 'events' => [$ev]]);
        $ts = (string) time();
        $headers = ['X-Monitor-Collector' => $uuid, 'X-Monitor-Timestamp' => $ts, 'X-Monitor-Signature' => hash_hmac('sha256', $ts.'.'.$body, 'topsecret')];
        $res = $this->call('POST', '/internal/monitor/events', [], [], [], array_merge(['CONTENT_TYPE' => 'application/json'], $this->transformHeadersToServerVars($headers)), $body);
        $res->assertStatus(200)->assertJsonPath('accepted.0', $ev['event_id']);
        $res = $this->call('POST', '/internal/monitor/events', [], [], [], array_merge(['CONTENT_TYPE' => 'application/json'], $this->transformHeadersToServerVars($headers)), $body);
        $res->assertStatus(200)->assertJsonPath('duplicate.0', $ev['event_id']);
        $ev2 = $ev;
        $ev2['summary'] = 'different payload';
        $body2 = json_encode(['collector_uuid' => $uuid, 'events' => [$ev2]]);
        $headers['X-Monitor-Signature'] = hash_hmac('sha256', $ts.'.'.$body2, 'topsecret');
        $this->call('POST', '/internal/monitor/events', [], [], [], array_merge(['CONTENT_TYPE' => 'application/json'], $this->transformHeadersToServerVars($headers)), $body2)
            ->assertStatus(409)->assertJsonPath('conflict.0', $ev['event_id']);
        $ev3 = $this->event(['application_code' => 'WMS']);
        $body3 = json_encode(['collector_uuid' => $uuid, 'events' => [$ev3]]);
        $headers['X-Monitor-Signature'] = hash_hmac('sha256', $ts.'.'.$body3, 'topsecret');
        $this->call('POST', '/internal/monitor/events', [], [], [], array_merge(['CONTENT_TYPE' => 'application/json'], $this->transformHeadersToServerVars($headers)), $body3)
            ->assertStatus(207)->assertJsonPath('rejected.0.reason', 'site_not_allowed_for_collector');
        $this->assertSame(1, \App\Models\Monitoring\Incident::count());
    }
}
