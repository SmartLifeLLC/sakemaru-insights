<?php

namespace Tests\Feature\Monitoring;

use App\Filament\Monitor\Pages\IncidentDetail;
use App\Filament\Monitor\Pages\Incidents;
use App\Filament\Monitor\Pages\Logs;
use App\Filament\Monitor\Pages\Notifications;
use App\Filament\Monitor\Pages\Rules;
use App\Models\Monitoring\AuditEvent;
use App\Models\Monitoring\Check;
use App\Models\Monitoring\Feature;
use App\Models\Monitoring\Incident;
use App\Models\Monitoring\RecheckRequest;
use App\Monitoring\Services\ObservationIngestor;
use Livewire\Livewire;

class PageActionsTest extends MonitoringTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('monitor'));
    }

    public function test_detail_page_actions_drive_the_workflow_with_audit(): void
    {
        $this->bootRegistries();
        app(ObservationIngestor::class)->ingestInternal([$this->event(['source_kind' => 'CHECK', 'check_key' => 'trade.coupon_daily', 'outcome' => 'VIOLATION', 'run_id' => 1, 'business_date' => '2026-10-30', 'scope' => ['business_date' => '2026-10-30'], 'error_code' => null])]);
        $this->actingAs($this->user());

        $c = Livewire::test(IncidentDetail::class, ['publicId' => 'MON-000001']);
        $c->callAction('acknowledge', ['reason' => '確認した'])->assertHasNoActionErrors();
        $this->assertSame('ACKNOWLEDGED', Incident::sole()->workflow_status->value);
        $c->callAction('start', ['reason' => '対応開始'])->assertHasNoActionErrors();
        $this->assertSame('IN_PROGRESS', Incident::sole()->workflow_status->value);
        $c->callAction('recheck')->assertHasNoActionErrors();
        $this->assertSame(1, RecheckRequest::count());
        $this->assertSame('2026-10-30', RecheckRequest::sole()->scope_json['business_date']);
        $c->callAction('markRecovered', ['reason' => '再照合で一致'])->assertHasNoActionErrors();
        $c->callAction('resolveIncident', ['cause_category' => 'data_delay', 'action_taken' => '受信後に再集計', 'verification' => '4指標一致'])->assertHasNoActionErrors();
        $this->assertSame('RESOLVED', Incident::sole()->workflow_status->value);
        $c->callAction('close')->assertHasNoActionErrors();
        $this->assertSame('CLOSED', Incident::sole()->workflow_status->value);
        $this->assertGreaterThanOrEqual(5, AuditEvent::count());
    }

    public function test_respond_permission_is_enforced_server_side(): void
    {
        $this->bootRegistries();
        app(ObservationIngestor::class)->ingestInternal([$this->event()]);
        config()->set('monitoring.permissions', ['monitor.view' => ['*'], 'monitor.respond' => ['nobody@example.com']]);
        $this->actingAs($this->user());
        $c = Livewire::test(IncidentDetail::class, ['publicId' => 'MON-000001']);
        $c->callAction('acknowledge', ['reason' => 'x']);
        $this->assertSame('OPEN', Incident::sole()->workflow_status->value);
        $this->assertStringContainsString('権限', $c->get('flash'));
    }

    public function test_logs_page_filters_and_pages_without_offset(): void
    {
        $this->bootRegistries();
        $events = [];
        for ($i = 0; $i < 7; $i++) {
            $events[] = $this->event(['severity' => $i % 2 ? 'WARNING' : 'INFO', 'error_code' => 'LOG_X', 'summary' => "row{$i}"]);
        }
        app(ObservationIngestor::class)->ingestInternal($events);
        $this->actingAs($this->user());
        $c = Livewire::test(Logs::class)->set('pageSize', 5);
        $this->assertCount(5, $c->instance()->page()->items);
        $c->call('setTab', 'warning');
        $this->assertCount(3, $c->instance()->page()->items);
        $c->call('setTab', 'all')->set('incident', 'bad-id')->call('applyFilters');
        $this->assertNull($c->instance()->page());
        $this->assertStringContainsString('MON-', (string) $c->get('error'));
    }

    public function test_logs_drawer_and_csv_export_are_redacted_current_page_only(): void
    {
        $this->bootRegistries();
        app(ObservationIngestor::class)->ingestInternal([$this->event(['summary' => '失敗 password=hunter2'])]);
        $this->actingAs($this->user());
        $c = Livewire::test(Logs::class);
        $id = \App\Models\Monitoring\LogEntry::query()->value('id');
        $c->call('openLog', $id)->assertSee('ログ詳細 #'.$id)->assertSee('ログ本文をコピー');
        $this->assertStringNotContainsString('hunter2', $c->instance()->openLogData()['copy']);
        $c->call('closeDrawer')->assertDontSee('ログ詳細 #');
        $res = $c->call('exportCsv');
        $res->assertFileDownloaded();
        $c->set('search', 'MON-000001')->call('applySearch');
        $this->assertSame('MON-000001', $c->get('incident'));
    }

    public function test_incident_list_drawer_quick_acknowledge_uses_optimistic_lock(): void
    {
        $this->bootRegistries();
        app(ObservationIngestor::class)->ingestInternal([$this->event()]);
        $this->actingAs($this->user());
        $i = Incident::sole();
        $c = Livewire::test(Incidents::class)->call('openIncident', 'MON-000001')->assertSee('詳細を開く');
        $c->call('quick', 'acknowledge', (int) $i->version + 5);
        $this->assertSame('OPEN', $i->fresh()->workflow_status->value, '古い版では更新しない');
        $c->call('quick', 'acknowledge', (int) $i->version);
        $this->assertSame('ACKNOWLEDGED', $i->fresh()->workflow_status->value);
        $this->assertTrue(AuditEvent::query()->exists() || \App\Models\Monitoring\IncidentEvent::query()->where('event_type', 'ACKNOWLEDGE')->exists());
    }

    public function test_rules_three_level_toggles_rule_edit_priority_and_test_tab(): void
    {
        $this->bootRegistries();
        $this->actingAs($this->user());
        $f = Feature::query()->where('feature_key', 'wms.jx')->sole();
        $c = Livewire::test(Rules::class);
        $c->callAction('toggleFeature', ['reason' => ''], ['id' => $f->id])->assertHasActionErrors(['reason']);
        $c = Livewire::test(Rules::class);
        $c->callAction('toggleFeature', ['reason' => 'JX を使っている'], ['id' => $f->id])->assertHasNoActionErrors();
        $this->assertSame(! (bool) $f->enabled, (bool) $f->fresh()->enabled);

        $check = Check::query()->where('check_key', 'wms.jx_transmission')->sole();
        $c->set('tab', 'rules')->callAction('editRule', ['enabled' => true, 'interval_seconds' => 600, 'grace_seconds' => 120, 'severity' => 'CRITICAL', 'action' => 'record_only',
            'config' => json_encode(['silence_hours' => 72, 'evil' => 1]), 'reason' => '夜間は記録のみ'], ['id' => $check->id])->assertHasNoActionErrors();
        $check->refresh();
        $this->assertSame('CRITICAL', $check->rule_json['severity']);
        $this->assertSame('record_only', $check->rule_json['action']);
        $this->assertSame(600, (int) $check->interval_seconds);
        $this->assertSame(72, $check->config_json['silence_hours']);
        $this->assertArrayNotHasKey('evil', $check->config_json, '許可キーだけ保存');
        app(\App\Monitoring\Services\CheckRegistry::class)->sync(app(\App\Monitoring\Services\SiteRegistry::class));
        $this->assertSame(600, (int) $check->fresh()->interval_seconds, '同期しても運用値を保持');

        $wms = Check::query()->where('site_id', $check->site_id)->orderBy('priority')->orderBy('id')->pluck('id')->all();
        $c->call('moveCheck', $wms[1], 'up');
        $this->assertSame($wms[1], Check::query()->where('site_id', $check->site_id)->orderBy('priority')->orderBy('id')->value('id'), '優先順位を上げる');

        $c->set('tab', 'test')->set('testMetric', 'cpu_pct')->set('testValues', implode(',', array_fill(0, 11, 95)))->call('runTest');
        $this->assertSame('error', $c->get('testResult')['level']);
        $c->set('testValues', implode(',', array_fill(0, 8, 95)))->call('runTest');
        $this->assertSame('ok', $c->get('testResult')['level'], '4 分では出ない');
        $c->set('testValues', '1, x')->call('runTest');
        $this->assertArrayHasKey('error', $c->get('testResult'));
    }

    public function test_rules_require_manage_permission(): void
    {
        $this->bootRegistries();
        config()->set('monitoring.permissions', ['monitor.view' => ['*'], 'monitor.manage' => ['nobody@example.com']]);
        $this->actingAs($this->user());
        $f = Feature::query()->where('feature_key', 'wms.jx')->sole();
        $before = (bool) $f->enabled;
        Livewire::test(Rules::class)->assertActionHidden('toggleFeature', ['id' => $f->id]);
        Livewire::test(Rules::class)->call('moveCheck', Check::query()->value('id'), 'down')->assertSet('flashLevel', 'error');
        $this->assertSame($before, (bool) $f->fresh()->enabled);
    }

    public function test_nodes_and_probe_targets_are_edited_with_validation(): void
    {
        $this->bootRegistries();
        config()->set('monitoring.probe.allowed_hosts', ['wms.example.jp']);
        $this->actingAs($this->user());
        $node = \App\Models\Monitoring\Node::query()->create(['node_code' => 'web1', 'display_name' => 'web1', 'role' => 'web', 'enabled' => true, 'registered_from' => 'env']);
        $c = Livewire::test(Rules::class)->set('tab', 'nodes');
        $c->callAction('editNode', ['display_name' => '本番 Web', 'enabled' => true, 'th' => ['cpu_pct' => ['error' => 95, 'duration' => 600]], 'reason' => '夜間バッチで CPU が高い'], ['id' => $node->id])->assertHasNoActionErrors();
        $node->refresh();
        $this->assertSame('本番 Web', $node->display_name);
        $this->assertSame('web1', $node->node_code, 'ノードコードは変えられない');
        $this->assertSame(95.0, (float) $node->thresholds_json['cpu_pct']['error']);
        $c->callAction('editNode', ['display_name' => 'x', 'enabled' => true, 'th' => ['cpu_pct' => ['error' => 500]], 'reason' => '範囲外'], ['id' => $node->id]);
        $this->assertSame('error', $c->get('flashLevel'));

        $c->callAction('addProbe', ['site' => 'WMS', 'display_name' => '/up', 'url' => 'https://evil.example.com/up', 'expect_status' => 200, 'reason' => '追加']);
        $this->assertSame(0, \App\Models\Monitoring\Target::count(), '許可外ホストは登録しない');
        $c->callAction('addProbe', ['site' => 'WMS', 'display_name' => '/up', 'url' => 'https://wms.example.jp/up', 'expect_status' => 200, 'reason' => '追加'])->assertHasNoActionErrors();
        $this->assertSame(1, \App\Models\Monitoring\Target::count());
    }

    public function test_notification_settings_contacts_and_secrets_actions(): void
    {
        $this->bootRegistries();
        $this->actingAs($this->user());
        $c = Livewire::test(Notifications::class);
        $c->callAction('mailSettings', ['enabled' => true, 'recipients_text' => "admin@sakemaru.ai\nops@sakemaru.ai", 'include_reminder' => false, 'include_recovery' => true, 'min_interval_minutes' => 10, 'max_per_hour' => 12, 'reason' => '復旧もメール'])->assertHasNoActionErrors();
        $this->assertSame(12, app(\App\Monitoring\Services\NotificationSettings::class)->mail()['max_per_hour']);
        $this->assertSame(['admin@sakemaru.ai', 'ops@sakemaru.ai'], app(\App\Monitoring\Services\NotificationSettings::class)->mail()['recipients']);
        $c->callAction('phoneSettings', ['enabled' => true, 'window_from' => '21:00', 'window_to' => '07:00', 'severities' => ['CRITICAL'], 'delay_minutes' => 10, 'cooldown_minutes' => 60, 'per_incident_cooldown_hours' => 24, 'max_columns_per_window' => 2, 'max_calls_per_contact_per_window' => 3, 'reason' => '重大のみ'])->assertHasNoActionErrors();
        $p = app(\App\Monitoring\Services\NotificationSettings::class)->phone();
        $this->assertSame('21:00', $p['window_from']);
        $this->assertSame([10, 60, 24, 2, 3], [$p['delay_minutes'], $p['cooldown_minutes'], $p['per_incident_cooldown_hours'], $p['max_columns_per_window'], $p['max_calls_per_contact_per_window']]);
        $c->callAction('contact', ['name' => '山田', 'phone' => '090-1111-2222', 'enabled' => true, 'active_from' => null, 'active_to' => null, 'reason' => '追加'])->assertHasNoActionErrors();
        $c->callAction('contact', ['name' => '佐藤', 'phone' => '090-3333-4444', 'enabled' => true, 'active_from' => null, 'active_to' => null, 'reason' => '追加'])->assertHasNoActionErrors();
        $sato = \App\Models\Monitoring\Contact::query()->where('name', '佐藤')->sole();
        $c->call('moveContact', $sato->id, 'up');
        $this->assertSame('佐藤', \App\Models\Monitoring\Contact::query()->orderBy('order_no')->value('name'));
        $c->callAction('secrets', ['v' => ['smtp__host' => 'smtp.example.jp', 'smtp__password' => 'Sup3rS3cret!', 'twilio__auth_token' => ''], 'clear' => [], 'reason' => 'SMTP 登録'])->assertHasNoActionErrors();
        $this->assertSame('smtp.example.jp', app(\App\Monitoring\Services\SecretStore::class)->get('smtp.host'));
        $this->assertNull(app(\App\Monitoring\Services\SecretStore::class)->get('twilio.auth_token'), '空欄は変更しない');
        $c->call('runTest', 'smtp_connection');
        $this->assertNotNull($c->get('flash'));
        $this->assertStringNotContainsString('Sup3rS3cret', (string) $c->get('flash'));
        $this->assertTrue(AuditEvent::query()->where('action', 'notify.test.smtp_connection')->exists());

        config()->set('monitoring.permissions', ['monitor.view' => ['*'], 'monitor.delivery_admin' => ['nobody@example.com']]);
        $c2 = Livewire::test(Notifications::class)->call('runTest', 'mail');
        $this->assertStringContainsString('配送管理者', (string) $c2->get('flash'));
        $c2->assertActionHidden('secrets');
    }

    public function test_rule_config_values_are_validated_by_type_and_format(): void
    {
        $this->bootRegistries();
        $this->actingAs($this->user());
        $svc = app(\App\Monitoring\Services\RuleService::class);
        $check = Check::query()->where('check_key', 'wms.jx_order_generation')->sole();
        foreach ([['slots' => 'abc'], ['slots' => ['25:99']], ['slots' => ['xyz' => ['13:30']]], ['grace_minutes' => -1], ['grace_minutes' => 'x'], ['grace_minutes' => 99999999]] as $bad) {
            try {
                $svc->update($check, ['config' => $bad], ['id' => 1, 'name' => 't'], '不正値');
                $this->fail(json_encode($bad).' は拒否されるべき');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('不正', $e->getMessage());
            }
        }
        $svc->update($check, ['config' => ['slots' => ['MON' => '13:30', 'sun' => ['23:35']], 'grace_minutes' => '45']], ['id' => 1, 'name' => 't'], '正式スケジュール');
        $check->refresh();
        $this->assertSame(['mon' => ['13:30'], 'sun' => ['23:35']], $check->config_json['slots']);
        $this->assertSame(45, $check->config_json['grace_minutes']);
        $op = Check::query()->where('check_key', 'delivery.operation_sync')->sole();
        try {
            $svc->update($op, ['config' => ['unfinished_check_time' => '6:00']], ['id' => 1, 'name' => 't'], 'x');
            $this->fail();
        } catch (\InvalidArgumentException) {
        }
        $svc->update($op, ['config' => ['unfinished_check_time' => '06:30']], ['id' => 1, 'name' => 't'], 'ok');
        $this->assertSame('06:30', $op->fresh()->config_json['unfinished_check_time']);
    }

    public function test_pause_button_is_on_every_page_and_start_action_can_pause(): void
    {
        $this->bootRegistries();
        app(ObservationIngestor::class)->ingestInternal([$this->event()]);
        $this->actingAs($this->user());
        $this->get('/monitor')->assertSee('通知 運用中')->assertSee('alert-state-running')->assertDontSee('通知 停止中');
        $c = Livewire::test(\App\Filament\Monitor\Pages\Dashboard::class);
        $c->callAction('pauseAlerts', ['minutes' => 30, 'reason' => ''])->assertHasActionErrors(['reason']);
        $c = Livewire::test(\App\Filament\Monitor\Pages\Dashboard::class);
        $c->callAction('pauseAlerts', ['minutes' => 30, 'reason' => '障害対応中'])->assertHasNoActionErrors();
        $this->assertNotNull(app(\App\Monitoring\Services\NotificationSettings::class)->pause());
        $this->get('/monitor/logs')->assertSee('通知 停止中 〜')->assertSee('alert-state-paused')->assertDontSee('alert-state-running');
        // 停止中の案内は本文のバナーではなく、ベル（お知らせ）のモーダルに出る
        $this->get('/monitor/logs')->assertDontSee('class="banner');
        $notices = (string) Livewire::test(\App\Filament\Monitor\Pages\Logs::class)->instance()->noticesAction()->getModalContent()->render();
        $this->assertStringContainsString('アラート停止中', $notices);
        $this->assertStringContainsString('障害対応中', $notices);
        // 推奨設定（info: ホスト外死活監視など）は折りたたみに分け、バッジには数えない
        config()->set('monitoring.notification.external_heartbeat_url', null);
        $this->assertStringContainsString('推奨設定（任意）', (string) Livewire::test(\App\Filament\Monitor\Pages\Logs::class)->instance()->noticesAction()->getModalContent()->render());
        $all = app(\App\Monitoring\Services\MonitorHealthService::class)->banners();
        $this->assertNotEmpty(array_filter($all, fn ($b) => $b['level'] === 'info'), 'info の項目がある前提');
        $badge = count(array_filter($all, fn ($b) => $b['level'] !== 'info')) + \App\Models\Monitoring\Incident::query()->whereNull('acknowledged_at')->count();
        preg_match('/data-count="(\d+)"/', (string) $this->get('/monitor/logs')->getContent(), $m);
        $this->assertSame((string) $badge, $m[1] ?? 'none', 'バッジは info を除いた件数');
        Livewire::test(\App\Filament\Monitor\Pages\Logs::class)->callAction('resumeAlerts')->assertHasNoActionErrors();
        $this->assertNull(app(\App\Monitoring\Services\NotificationSettings::class)->pause());

        // 対応開始と同時に停止
        $c = Livewire::test(IncidentDetail::class, ['publicId' => 'MON-000001']);
        $c->callAction('start', ['reason' => '調査開始', 'pause_alerts' => true, 'pause_minutes' => 120])->assertHasNoActionErrors();
        $this->assertSame('IN_PROGRESS', Incident::sole()->workflow_status->value);
        $p = app(\App\Monitoring\Services\NotificationSettings::class)->pause();
        $this->assertNotNull($p);
        $this->assertStringContainsString('MON-000001', $p['reason']);

        // 対応権限が無ければボタンは出ず、実行も拒否
        config()->set('monitoring.permissions', ['monitor.view' => ['*'], 'monitor.respond' => ['nobody@example.com']]);
        Livewire::test(\App\Filament\Monitor\Pages\Dashboard::class)->assertActionHidden('resumeAlerts');
    }

    /** 華 本番で .env 反映前に既定名 local で自動登録された残骸を消せること。受信中のノードは削除できない。 */
    public function test_stale_node_registration_can_be_deleted_and_its_incidents_are_closed(): void
    {
        $this->bootRegistries();
        $this->actingAs($this->user());
        $now = \Carbon\CarbonImmutable::now('UTC');
        $stale = \App\Models\Monitoring\Node::query()->create(['node_code' => 'local', 'display_name' => 'local', 'role' => 'web', 'enabled' => true, 'registered_from' => 'env', 'last_heartbeat_at' => $now->subMinutes(15)]);
        $live = \App\Models\Monitoring\Node::query()->create(['node_code' => 'web1', 'display_name' => 'web1', 'role' => 'web', 'enabled' => true, 'registered_from' => 'env', 'last_heartbeat_at' => $now->subMinutes(1)]);
        \App\Models\Monitoring\ServerMetric::query()->create(['node_id' => $stale->id, 'bucket_start' => $now->subMinutes(20), 'sample_count' => 10]);
        app(ObservationIngestor::class)->ingestInternal([
            $this->event(['application_code' => 'INSIGHTS', 'check_key' => 'node.resources', 'scope' => ['node' => 'local', 'metric' => 'process', 'process' => 'php-fpm'], 'summary' => 'local で PHP-FPM の稼働が確認できません', 'severity' => 'CRITICAL']),
            $this->event(['application_code' => 'INSIGHTS', 'check_key' => 'node.resources', 'scope' => ['node' => 'web1', 'metric' => 'cpu_pct'], 'summary' => 'web1 CPU', 'severity' => 'ERROR']),
        ]);
        $this->assertSame(2, Incident::query()->whereIn('workflow_status', \App\Monitoring\Enums\WorkflowStatus::unresolvedValues())->count());

        $c = Livewire::test(Rules::class)->set('tab', 'nodes');
        $c->callAction('deleteNode', ['reason' => '受信中'], ['id' => $live->id]);
        $this->assertSame('error', $c->get('flashLevel'), '受信中のノードは削除できない');
        $this->assertNotNull(\App\Models\Monitoring\Node::query()->find($live->id));

        $c->callAction('deleteNode', ['reason' => '名前変更前の残骸'], ['id' => $stale->id])->assertHasNoActionErrors();
        $this->assertSame('ok', $c->get('flashLevel'));
        $this->assertNull(\App\Models\Monitoring\Node::query()->where('node_code', 'local')->first());
        $this->assertSame(0, \App\Models\Monitoring\ServerMetric::query()->where('node_id', $stale->id)->count());
        $closed = Incident::query()->where('scope_json->node', 'local')->sole();
        $this->assertSame('CLOSED', $closed->workflow_status->value);
        $this->assertSame('RECOVERED', $closed->observation_state->value);
        $this->assertSame(1, Incident::query()->whereIn('workflow_status', \App\Monitoring\Enums\WorkflowStatus::unresolvedValues())->count(), '他ノードの案件は残る');
        $this->assertSame(1, \App\Models\Monitoring\AuditEvent::query()->where('action', 'node.delete')->count());
    }

    /** 一括対処: 選択した案件に同じ操作を順に記録し、状態が合わない案件はスキップして件数を出す。 */
    public function test_bulk_action_applies_to_selected_incidents_and_skips_invalid_ones(): void
    {
        $this->bootRegistries();
        app(ObservationIngestor::class)->ingestInternal([
            $this->event(['scope' => ['fp' => 'a'], 'summary' => 'A']),
            $this->event(['scope' => ['fp' => 'b'], 'summary' => 'B']),
            $this->event(['scope' => ['fp' => 'c'], 'summary' => 'C']),
        ]);
        $this->actingAs($this->user());
        $ids = Incident::query()->orderBy('id')->pluck('public_id')->all();
        $this->assertCount(3, $ids);

        // 2 件を確認済みに
        $c = Livewire::test(Incidents::class)->set('selected', [$ids[0], $ids[1]]);
        $c->callAction('bulk', ['action' => 'acknowledge', 'reason' => '一括で把握'])->assertHasNoActionErrors();
        $this->assertSame('ok', $c->get('flashLevel'));
        $this->assertSame([], $c->get('selected'));
        $this->assertSame(['ACKNOWLEDGED', 'ACKNOWLEDGED', 'OPEN'], Incident::query()->orderBy('id')->get()->map(fn ($i) => $i->workflow_status->value)->all());

        // 全選択 → 復旧確認は理由必須
        $c = Livewire::test(Incidents::class)->call('toggleSelectAll');
        $this->assertCount(3, $c->get('selected'));
        $c->callAction('bulk', ['action' => 'mark_recovered', 'reason' => ''])->assertHasActionErrors(['reason']);

        // 1 件だけ復旧確認してから「解決して終了」を 3 件に → 1 件成功・2 件スキップ（未復旧）
        $svc = app(\App\Monitoring\Services\IncidentService::class);
        $first = Incident::query()->orderBy('id')->first();
        $svc->act($first, 'mark_recovered', (int) $first->version, ['id' => 1, 'name' => 't'], ['reason' => '再確認した'], 'k1');
        $c = Livewire::test(Incidents::class)->set('selected', $ids);
        $c->callAction('bulk', ['action' => 'resolve_close', 'cause_category' => 'monitoring', 'action_taken' => '誤検知の修正をリリース', 'verification' => '次回チェックで正常', 'reason' => ''])->assertHasNoActionErrors();
        $this->assertSame('warn', $c->get('flashLevel'));
        $this->assertStringContainsString('1 件を記録', (string) $c->get('flash'));
        $this->assertStringContainsString('スキップ 2 件', (string) $c->get('flash'));
        $this->assertSame('CLOSED', $first->refresh()->workflow_status->value);
        $this->assertSame(2, Incident::query()->whereIn('workflow_status', \App\Monitoring\Enums\WorkflowStatus::unresolvedValues())->count());

        // ログ由来など自動復旧しない案件: 復旧確認 → 解決 → 終了をまとめて
        $c = Livewire::test(Incidents::class)->set('selected', [$ids[1], $ids[2]]);
        $c->callAction('bulk', ['action' => 'recover_resolve_close', 'cause_category' => 'application_bug', 'action_taken' => '修正済み', 'verification' => 'ログで再発なし', 'reason' => ''])->assertHasActionErrors(['reason']);
        $c = Livewire::test(Incidents::class)->set('selected', [$ids[1], $ids[2]]);
        $c->callAction('bulk', ['action' => 'recover_resolve_close', 'cause_category' => 'application_bug', 'action_taken' => '修正済み', 'verification' => 'ログで再発なし', 'reason' => '対象リクエストを再確認し再発なし'])->assertHasNoActionErrors();
        $this->assertSame('ok', $c->get('flashLevel'));
        $this->assertSame(0, Incident::query()->whereIn('workflow_status', \App\Monitoring\Enums\WorkflowStatus::unresolvedValues())->count());

        // 対応権限が無ければボタンは出ない
        config()->set('monitoring.permissions', ['monitor.view' => ['*'], 'monitor.respond' => ['nobody@example.com']]);
        Livewire::test(Incidents::class)->assertActionHidden('bulk');
    }

    /** 華 本番の初期設定: 検出済みの推奨値をボタン 1 つで全機能に適用できる（未判定は触らない・監査あり）。 */
    public function test_apply_recommended_turns_on_detected_features_at_once(): void
    {
        $this->bootRegistries();
        $this->actingAs($this->user());
        \App\Models\Monitoring\Feature::query()->whereIn('feature_key', ['wms.jx', 'wms.auto_order', 'core.stats'])->update(['enabled' => false, 'recommended_enabled' => true, 'recommended_reason' => '直近に実行あり']);
        \App\Models\Monitoring\Feature::query()->where('feature_key', 'wms.http')->update(['enabled' => true, 'recommended_enabled' => false, 'recommended_reason' => '監視対象が未登録']);
        \App\Models\Monitoring\Feature::query()->where('feature_key', 'wms.queue')->update(['enabled' => false, 'recommended_enabled' => null]);
        $c = Livewire::test(Rules::class);
        $c->callAction('applyRecommended', ['reason' => '華様 本番: 使用中の機能を監視'])->assertHasNoActionErrors();
        $this->assertSame('ok', $c->get('flashLevel'));
        $this->assertStringContainsString('ON 3 件 / OFF 1 件', (string) $c->get('flash'));
        $on = \App\Models\Monitoring\Feature::query()->whereIn('feature_key', ['wms.jx', 'wms.auto_order', 'core.stats'])->pluck('enabled')->all();
        $this->assertSame([true, true, true], array_map(fn ($v) => (bool) $v, $on));
        $this->assertFalse((bool) \App\Models\Monitoring\Feature::query()->where('feature_key', 'wms.http')->sole()->enabled);
        $this->assertFalse((bool) \App\Models\Monitoring\Feature::query()->where('feature_key', 'wms.queue')->sole()->enabled, '未判定は触らない');
        $this->assertSame(4, \App\Models\Monitoring\AuditEvent::query()->whereIn('action', ['feature.enable', 'feature.disable'])->count());
        Livewire::test(Rules::class)->assertActionDisabled('applyRecommended');
    }
}
