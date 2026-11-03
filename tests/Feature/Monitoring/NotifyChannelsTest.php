<?php

namespace Tests\Feature\Monitoring;

use App\Mail\MonitorAlertMail;
use App\Models\Monitoring\AuditEvent;
use App\Models\Monitoring\CallAttempt;
use App\Models\Monitoring\Contact;
use App\Models\Monitoring\Incident;
use App\Models\Monitoring\Notification;
use App\Models\Monitoring\Secret;
use App\Monitoring\Notify\MailNotifier;
use App\Monitoring\Notify\PhoneNotifier;
use App\Monitoring\Notify\TwilioClient;
use App\Monitoring\Services\NotificationDispatcher;
use App\Monitoring\Services\NotificationSettings;
use App\Monitoring\Services\ObservationIngestor;
use App\Monitoring\Services\SecretStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/** v2 V-116〜121 / V-150〜166: メール・電話・接続情報 */
class NotifyChannelsTest extends MonitoringTestCase
{
    private const ACTOR = ['id' => 1, 'name' => '配送管理者'];

    // 形式チェック（AC＋16 進 32 桁）を通すダミー。実物ではない（GitHub の秘密検出に掛からないよう連結で書く）
    private const SID = 'AC'.'0123456789abcdef'.'0123456789abcdef';

    private const TOKEN = 'twilio-auth-token-9876';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootRegistries();
        putenv('MONITOR_SLACK_ERROR_WEBHOOK=https://hooks.slack.com/services/T/B/x');
        config()->set('app.url', 'https://insights.example.jp');
    }

    private function secrets(): SecretStore
    {
        return app(SecretStore::class);
    }

    private function configureSmtp(): void
    {
        config()->set('monitoring.mail.enabled', true);
        $this->secrets()->save(['smtp.host' => 'smtp.example.jp', 'smtp.port' => '587', 'smtp.username' => 'mailer', 'smtp.password' => 'Sup3rS3cret!', 'smtp.encryption' => 'tls', 'smtp.from_address' => 'monitor@sakemaru.ai'], self::ACTOR, 'SMTP 設定');
        app(NotificationSettings::class)->forget();
    }

    private function configurePhone(): void
    {
        config()->set('monitoring.phone.enabled', true);
        config()->set('monitoring.deployment.environment', 'production');
        // 既存のテストは発信の手順を見る。多発防止（猶予・クールダウン・上限）は専用テストで確認する
        config()->set('monitoring.phone.delay_minutes', 0);
        config()->set('monitoring.phone.cooldown_minutes', 0);
        config()->set('monitoring.phone.max_columns_per_window', 0);
        config()->set('monitoring.phone.max_calls_per_contact_per_window', 0);
        config()->set('monitoring.phone.per_incident_cooldown_hours', 0);
        $this->secrets()->save(['twilio.account_sid' => self::SID, 'twilio.auth_token' => self::TOKEN, 'twilio.from_number' => '+815012345678'], self::ACTOR, 'Twilio 設定');
        Contact::query()->create(['name' => '山田', 'phone_e164' => '+819011112222', 'order_no' => 1, 'enabled' => true]);
        Contact::query()->create(['name' => '佐藤', 'phone_e164' => '+819033334444', 'order_no' => 2, 'enabled' => true]);
        app(NotificationSettings::class)->forget();
    }

    private function dispatch(?CarbonImmutable $now = null): array
    {
        return app(NotificationDispatcher::class)->dispatch($now);
    }

    // ------------------------------------------------------------------ 接続情報（V-161〜166）

    public function test_secrets_are_encrypted_masked_and_screen_overrides_env(): void
    {
        putenv('MAIL_HOST=env-smtp.example.jp');
        $s = $this->secrets();
        $this->assertSame('env-smtp.example.jp', $s->get('smtp.host'));
        $this->assertSame('env', $s->source('smtp.host'));
        $this->assertNull($s->get('smtp.password'));
        $this->assertNull($s->source('smtp.password'), '未設定');

        $changed = $s->save(['smtp.host' => 'smtp.example.jp', 'smtp.password' => 'Sup3rS3cret!', 'smtp.username' => ''], self::ACTOR, '設定');
        $this->assertSame(['smtp.host', 'smtp.password'], $changed, '空欄は変更しない');
        $this->assertSame('smtp.example.jp', $s->get('smtp.host'), '画面 > .env');
        $this->assertSame('screen', $s->source('smtp.host'));
        $raw = Secret::query()->pluck('value_encrypted')->implode('|');
        $this->assertStringNotContainsString('Sup3rS3cret', $raw, 'DB に平文が無い');
        $this->assertStringNotContainsString('smtp.example.jp', $raw);
        $this->assertSame('••••••••ret!', $s->display('smtp.password'));
        $this->assertSame('smtp.example.jp', $s->display('smtp.host'), '秘密でない値はそのまま');
        $audit = AuditEvent::query()->where('action', 'secrets.update')->sole();
        $this->assertStringNotContainsString('Sup3rS3cret', json_encode($audit->toArray()), '監査に値を残さない');

        $s->save([], self::ACTOR, '画面の設定を戻す', ['smtp.host']);
        $this->assertSame('env-smtp.example.jp', $s->get('smtp.host'), '削除すると .env へ戻る');
        putenv('MAIL_HOST');

        foreach ([['twilio.account_sid', 'XX123'], ['twilio.from_number', '090-1111-2222'], ['smtp.port', 'abc'], ['slack.webhook_url', 'https://evil.example.com/x']] as [$k, $v]) {
            try {
                $s->save([$k => $v], self::ACTOR, '不正値');
                $this->fail($k);
            } catch (\InvalidArgumentException $e) {
                $this->assertStringNotContainsString($v, $e->getMessage(), '例外に値を含めない');
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $s->save(['smtp.host' => 'x'], self::ACTOR, '');
    }

    public function test_slack_webhook_from_screen_is_used(): void
    {
        putenv('MONITOR_SLACK_ERROR_WEBHOOK');
        $this->secrets()->save(['slack.webhook_url' => 'https://hooks.slack.com/services/T/B/screen'], self::ACTOR, '画面で登録');
        Http::fake(['hooks.slack.com/*' => Http::response('ok', 200)]);
        app(ObservationIngestor::class)->ingestInternal([$this->event()]);
        $this->assertSame(1, $this->dispatch()['sent']);
        Http::assertSent(fn ($r) => $r->url() === 'https://hooks.slack.com/services/T/B/screen');
    }

    // ------------------------------------------------------------------ メール（V-117〜118）

    public function test_mail_is_sent_for_new_error_with_subject_format_and_redacted_body(): void
    {
        Mail::fake();
        Http::fake(['hooks.slack.com/*' => Http::response('ok', 200)]);
        $this->configureSmtp();
        app(ObservationIngestor::class)->ingestInternal([$this->event(['summary' => '障害 password=hunter2 連絡先 03-1234-5678'])]);
        $this->assertSame(2, Notification::count(), 'Slack とメール');
        $stats = $this->dispatch();
        $this->assertSame(2, $stats['sent']);
        Mail::assertSent(MonitorAlertMail::class, function (MonitorAlertMail $m) {
            return $m->hasTo('admin@sakemaru.ai')
                && str_starts_with($m->subjectLine, '[華 LOCAL][乃蓮 TRADE][ERROR] MON-000001 新規: ')
                && ! str_contains($m->bodyText, 'hunter2') && ! str_contains($m->bodyText, '1234-5678')
                && str_contains($m->bodyText, 'monitor/incidents/MON-000001');
        });
        $mail = Notification::query()->where('route_key', 'mail')->sole();
        $this->assertSame('DELIVERED', $mail->status->value);
        $this->assertSame('mail', $mail->channel);
        $c = \App\Models\Monitoring\Counter::query()->where('counter_key', 'notification_hourly')->sole();
        $this->assertSame(2, (int) $c->counts_json['delivered']);
        $this->assertSame(1, (int) $c->counts_json['mail_delivered']);
    }

    public function test_mail_failures_retry_or_block_and_never_mark_delivered(): void
    {
        Http::fake(['hooks.slack.com/*' => Http::response('ok', 200)]);
        $this->configureSmtp();
        $result = ['ok' => false, 'permanent' => false, 'error' => 'Connection could not be established'];
        app()->instance(MailNotifier::class, new class(app(SecretStore::class), app(NotificationSettings::class), $result) extends MailNotifier
        {
            public function __construct(SecretStore $s, NotificationSettings $n, public array $result)
            {
                parent::__construct($s, $n);
            }

            public function send(string $subject, string $body, ?array $to = null): array
            {
                return $this->result;
            }
        });
        app(ObservationIngestor::class)->ingestInternal([$this->event()]);
        $this->dispatch();
        $mail = Notification::query()->where('route_key', 'mail')->sole();
        $this->assertSame('RETRY_WAIT', $mail->status->value);
        $this->assertSame('DELIVERED', Notification::query()->where('route_key', 'error_channel')->sole()->status->value, 'メールの失敗が Slack を止めない');

        app(MailNotifier::class)->result = ['ok' => false, 'permanent' => true, 'error' => '535 Authentication failed'];
        $this->dispatch(CarbonImmutable::now('UTC')->addHour());
        $this->assertSame('BLOCKED', $mail->fresh()->status->value);
        $this->assertTrue(Incident::query()->where('error_code', 'MONITOR_NOTIFICATION_BLOCKED')->exists());
    }

    public function test_unconfigured_mail_is_skipped_not_blocked(): void
    {
        config()->set('monitoring.mail.enabled', true);
        Http::fake(['hooks.slack.com/*' => Http::response('ok', 200)]);
        app(ObservationIngestor::class)->ingestInternal([$this->event()]);
        $this->dispatch();
        $mail = Notification::query()->where('route_key', 'mail')->sole();
        $this->assertSame('CANCELLED', $mail->status->value);
        $this->assertSame('not_configured', $mail->cancel_reason);
        $this->assertFalse(Incident::query()->where('error_code', 'MONITOR_NOTIFICATION_BLOCKED')->exists());
    }

    public function test_slack_blocked_triggers_fallback_mail_even_when_kind_is_not_mailed(): void
    {
        Mail::fake();
        $this->configureSmtp();
        config()->set('monitoring.mail.kinds', []); // 通常はメールしない設定
        app(NotificationSettings::class)->forget();
        Http::fake(['hooks.slack.com/*' => Http::response('no_service', 404)]);
        app(ObservationIngestor::class)->ingestInternal([$this->event()]);
        $this->assertSame(0, Notification::query()->where('route_key', 'mail')->count());
        $this->dispatch();
        $fallback = Notification::query()->where('route_key', 'mail')->where('incident_id', 1)->sole();
        $this->assertTrue((bool) $fallback->payload_snapshot_json['fallback']);
        $this->dispatch();
        Mail::assertSent(MonitorAlertMail::class);
    }

    public function test_reminder_and_recovery_mail_are_off_by_default(): void
    {
        $this->configureSmtp();
        $q = app(\App\Monitoring\Services\NotificationQueue::class);
        app(ObservationIngestor::class)->ingestInternal([$this->event()]);
        $i = Incident::sole();
        $this->assertFalse($q->mailWanted($i, \App\Monitoring\Enums\NotificationKind::REMINDER));
        $this->assertFalse($q->mailWanted($i, \App\Monitoring\Enums\NotificationKind::RECOVERY));
        app(NotificationSettings::class)->save('mail', ['include_recovery' => true, 'recipients' => ['ops@sakemaru.ai', 'admin@sakemaru.ai']], self::ACTOR, '復旧もメール');
        $this->assertTrue($q->mailWanted($i, \App\Monitoring\Enums\NotificationKind::RECOVERY));
        $this->assertSame(['ops@sakemaru.ai', 'admin@sakemaru.ai'], app(MailNotifier::class)->recipients());
        $this->expectException(\InvalidArgumentException::class);
        app(NotificationSettings::class)->save('mail', ['recipients' => ['not-an-email']], self::ACTOR, '不正');
    }

    // ------------------------------------------------------------------ 電話（V-150〜160）

    public function test_phone_window_boundaries_and_env_guard(): void
    {
        $s = app(NotificationSettings::class);
        $at = fn (string $t) => CarbonImmutable::parse('2026-11-03 '.$t, 'Asia/Tokyo');
        $this->assertFalse($s->inPhoneWindow($at('21:59')));
        $this->assertTrue($s->inPhoneWindow($at('22:00')));
        $this->assertTrue($s->inPhoneWindow($at('07:59')));
        $this->assertFalse($s->inPhoneWindow($at('08:00')));

        $this->configurePhone();
        $this->travelTo($at('12:00'));
        app(ObservationIngestor::class)->ingestInternal([$this->event()]);
        $this->assertSame(0, Notification::query()->where('route_key', 'phone')->count(), '日中は電話しない');
        $this->travelTo($at('23:00'));
        app(ObservationIngestor::class)->ingestInternal([$this->event(['scope' => ['fp' => 'night']])]);
        $this->assertSame(1, Notification::query()->where('route_key', 'phone')->count(), '夜間は電話');
        config()->set('monitoring.deployment.environment', 'staging');
        app(ObservationIngestor::class)->ingestInternal([$this->event(['scope' => ['fp' => 'stg']])]);
        $this->assertSame(1, Notification::query()->where('route_key', 'phone')->count(), 'MONITOR_PHONE_ALLOW_ENV 不一致では作らない');
        app(ObservationIngestor::class)->ingestInternal([$this->event(['severity' => 'WARNING', 'scope' => ['fp' => 'warn']])]);
        $this->assertSame(1, Notification::query()->where('route_key', 'phone')->count(), 'WARNING は電話しない');
    }

    private function twilioCalls(): array
    {
        return collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn ($r) => str_contains($r->url(), 'api.twilio.com') && str_ends_with($r->url(), 'Calls.json'))->values()->all();
    }

    private function tokenOf($request): string
    {
        parse_str($request->body(), $f);

        return basename((string) $f['Url']);
    }

    private function voice(string $token, string $suffix, array $params, ?string $sig = null)
    {
        $url = 'https://insights.example.jp/internal/monitor/voice/'.$token.$suffix;
        $sig ??= TwilioClient::sign($url, $params, self::TOKEN);

        return $this->call('POST', '/internal/monitor/voice/'.$token.$suffix, $params, [], [], ['HTTP_X_TWILIO_SIGNATURE' => $sig]);
    }

    public function test_phone_calls_contacts_in_order_and_ack_by_pressing_1(): void
    {
        $this->configurePhone();
        $this->travelTo(CarbonImmutable::parse('2026-11-03 23:00', 'Asia/Tokyo'));
        Http::fake(['hooks.slack.com/*' => Http::response('ok', 200), 'api.twilio.com/*' => Http::response(['sid' => 'CA'.str_repeat('1', 32)], 201)]);
        app(ObservationIngestor::class)->ingestInternal([$this->event(['summary' => '取込失敗 https://x.example.jp/a?b=1 担当 taro@example.jp SELECT * FROM users'])]);
        $this->dispatch();
        $calls = $this->twilioCalls();
        $this->assertCount(1, $calls);
        parse_str($calls[0]->body(), $f);
        $this->assertSame('+819011112222', $f['To'], '名簿の 1 番目');
        $this->assertTrue($calls[0]->hasHeader('Authorization'));
        $tokenA = $this->tokenOf($calls[0]);

        // TwiML: 読み上げに URL・メール・SQL を含めない
        $twiml = $this->voice($tokenA, '', ['CallSid' => 'CA'.str_repeat('1', 32)]);
        $twiml->assertOk();
        $this->assertStringContainsString('<Gather numDigits="1"', $twiml->getContent());
        $this->assertStringContainsString('language="ja-JP"', $twiml->getContent());
        foreach (['https://x.example.jp', 'taro@example.jp', 'SELECT'] as $bad) {
            $this->assertStringNotContainsString($bad, $twiml->getContent());
        }

        // 応答なし → 2 分後に次の担当者
        $this->voice($tokenA, '/status', ['CallSid' => 'CA'.str_repeat('1', 32), 'CallStatus' => 'no-answer'])->assertNoContent();
        $this->dispatch(CarbonImmutable::now('UTC')->addSeconds(60));
        $this->assertCount(1, $this->twilioCalls(), '2 分待つ');
        $this->dispatch(CarbonImmutable::now('UTC')->addSeconds(125));
        $calls = $this->twilioCalls();
        $this->assertCount(2, $calls);
        parse_str($calls[1]->body(), $f2);
        $this->assertSame('+819033334444', $f2['To']);
        $tokenB = $this->tokenOf($calls[1]);

        // 1 を押す → ACKNOWLEDGED、発信列は DELIVERED
        $g = $this->voice($tokenB, '/gather', ['CallSid' => 'CA'.str_repeat('1', 32), 'Digits' => '1']);
        $g->assertOk();
        $this->assertStringContainsString('確認を記録しました', $g->getContent());
        $i = Incident::sole();
        $this->assertSame('ACKNOWLEDGED', $i->workflow_status->value);
        $this->assertSame('佐藤（電話）', $i->acknowledged_name);
        $this->assertTrue(AuditEvent::query()->where('action', 'incident.acknowledge_by_phone')->exists());
        $this->dispatch(CarbonImmutable::now('UTC')->addSeconds(130));
        $this->assertSame('DELIVERED', Notification::query()->where('route_key', 'phone')->sole()->status->value);
        $this->assertCount(2, $this->twilioCalls(), '確認後は発信しない');
        $this->assertSame(['no-answer', 'acknowledged'], CallAttempt::query()->orderBy('id')->pluck('status')->all());
    }

    public function test_callbacks_reject_bad_signature_expired_and_unknown_tokens(): void
    {
        $this->configurePhone();
        $this->travelTo(CarbonImmutable::parse('2026-11-03 23:00', 'Asia/Tokyo'));
        Http::fake(['hooks.slack.com/*' => Http::response('ok', 200), 'api.twilio.com/*' => Http::response(['sid' => 'CA'.str_repeat('2', 32)], 201)]);
        app(ObservationIngestor::class)->ingestInternal([$this->event()]);
        $this->dispatch();
        $token = $this->tokenOf($this->twilioCalls()[0]);
        $this->voice($token, '/gather', ['Digits' => '1'], 'invalid-signature')->assertForbidden();
        $this->voice(str_repeat('Z', 40), '/gather', ['Digits' => '1'])->assertNotFound();
        $this->voice($token, '/gather', ['CallSid' => 'CA'.str_repeat('9', 32), 'Digits' => '1'])->assertForbidden(); // 別通話
        $this->assertSame('OPEN', Incident::sole()->workflow_status->value);
        $this->travel(31)->minutes();
        $this->voice($token, '/gather', ['Digits' => '1'])->assertStatus(401);
        $this->assertSame('OPEN', Incident::sole()->workflow_status->value);
    }

    public function test_three_rounds_without_ack_end_as_unanswered(): void
    {
        $this->configurePhone();
        Contact::query()->where('order_no', 2)->delete();
        $start = CarbonImmutable::parse('2026-11-03 23:00', 'Asia/Tokyo');
        $this->travelTo($start);
        Http::fake(['hooks.slack.com/*' => Http::response('ok', 200), 'api.twilio.com/*' => Http::response(['sid' => 'CA'.str_repeat('3', 32)], 201)]);
        app(ObservationIngestor::class)->ingestInternal([$this->event()]);
        $t = CarbonImmutable::now('UTC');
        for ($round = 1; $round <= 3; $round++) {
            $this->dispatch($t);
            $this->assertCount($round, $this->twilioCalls(), "{$round} 巡目");
            CallAttempt::query()->orderByDesc('id')->first()->update(['status' => 'busy', 'finished_at' => $t]);
            $this->dispatch($t->addSeconds(130));
            $this->assertCount($round, $this->twilioCalls(), '15 分は待つ');
            $t = $t->addSeconds(905);
        }
        $this->dispatch($t);
        $n = Notification::query()->where('route_key', 'phone')->sole();
        $this->assertSame('UNANSWERED', $n->status->value, '配送不可ではなく未応答');
        $this->assertCount(3, $this->twilioCalls());
    }

    public function test_twilio_4xx_blocks_5xx_retries_and_slack_still_sent(): void
    {
        $this->configurePhone();
        $this->travelTo(CarbonImmutable::parse('2026-11-04 02:00', 'Asia/Tokyo'));
        Http::fake(['hooks.slack.com/*' => Http::response('ok', 200), 'api.twilio.com/*' => Http::sequence()->push(['code' => 20500], 503)->push(['code' => 21211, 'message' => 'Invalid To +819011112222'], 400)]);
        app(ObservationIngestor::class)->ingestInternal([$this->event()]);
        $this->dispatch();
        $phone = Notification::query()->where('route_key', 'phone')->sole();
        $this->assertSame('RETRY_WAIT', $phone->status->value, '5xx は再試行');
        $this->assertSame('DELIVERED', Notification::query()->where('route_key', 'error_channel')->sole()->status->value);
        $this->dispatch(CarbonImmutable::now('UTC')->addMinutes(2));
        $this->assertSame('BLOCKED', $phone->fresh()->status->value, '4xx は配送不可');
        $this->assertStringNotContainsString('+8190', (string) CallAttempt::query()->pluck('sanitized_error')->implode(' '), 'エラーに番号を残さない');
        $this->assertTrue(Incident::query()->where('error_code', 'MONITOR_NOTIFICATION_BLOCKED')->exists());
    }

    public function test_storm_is_merged_into_one_call_and_ack_covers_all(): void
    {
        $this->configurePhone();
        $this->travelTo(CarbonImmutable::parse('2026-11-04 03:00', 'Asia/Tokyo'));
        Http::fake(['hooks.slack.com/*' => Http::response('ok', 200), 'api.twilio.com/*' => Http::response(['sid' => 'CA'.str_repeat('4', 32)], 201)]);
        $events = [];
        for ($i = 0; $i < 6; $i++) {
            $events[] = $this->event(['scope' => ['fp' => 'storm'.$i], 'error_code' => 'E'.$i]);
        }
        app(ObservationIngestor::class)->ingestInternal($events);
        $this->dispatch();
        $this->assertCount(1, $this->twilioCalls(), '1 本にまとめる');
        $this->assertSame(5, Notification::query()->where('route_key', 'phone')->where('status', 'CANCELLED')->where('cancel_reason', 'like', 'merged_into_call:%')->count());
        $this->assertSame(0, Notification::query()->where('route_key', 'phone')->where('status', 'DELIVERED')->count(), '未掲載を DELIVERED にしない');
        $token = $this->tokenOf($this->twilioCalls()[0]);
        $tw = $this->voice($token, '', ['CallSid' => 'CA'.str_repeat('4', 32)]);
        $this->assertStringContainsString('6 件', $tw->getContent());
        $this->voice($token, '/gather', ['CallSid' => 'CA'.str_repeat('4', 32), 'Digits' => '1'])->assertOk();
        $this->assertSame(6, Incident::query()->where('workflow_status', 'ACKNOWLEDGED')->count());
    }

    public function test_ack_elsewhere_cancels_remaining_calls(): void
    {
        $this->configurePhone();
        $this->travelTo(CarbonImmutable::parse('2026-11-04 01:00', 'Asia/Tokyo'));
        Http::fake(['hooks.slack.com/*' => Http::response('ok', 200), 'api.twilio.com/*' => Http::response(['sid' => 'CA'.str_repeat('5', 32)], 201)]);
        app(ObservationIngestor::class)->ingestInternal([$this->event()]);
        $this->dispatch();
        CallAttempt::query()->update(['status' => 'no-answer', 'finished_at' => now('UTC')]);
        $i = Incident::sole();
        app(\App\Monitoring\Services\IncidentService::class)->act($i, 'acknowledge', (int) $i->version, ['id' => 9, 'name' => '画面の担当'], [], 'k1');
        $this->dispatch(CarbonImmutable::now('UTC')->addMinutes(3));
        $n = Notification::query()->where('route_key', 'phone')->sole();
        $this->assertSame('CANCELLED', $n->status->value);
        $this->assertSame('acknowledged_elsewhere', $n->cancel_reason);
        $this->assertCount(1, $this->twilioCalls());
    }

    public function test_speakable_text_is_safe_and_short(): void
    {
        $t = PhoneNotifier::speakable('受注取込でエラー。詳細 https://wms.example.jp/x?id=1 連絡 090-1234-5678 / a.b@example.com SQLSTATE[23000] Duplicate entry password=abc '.str_repeat('長', 100));
        foreach (['https://', '090-1234', '@example.com', 'SQLSTATE', 'abc'] as $bad) {
            $this->assertStringNotContainsString($bad, $t);
        }
        $this->assertLessThanOrEqual(80, mb_strlen($t));
    }

    public function test_twilio_connection_test_and_signature(): void
    {
        $this->configurePhone();
        Http::fake(['api.twilio.com/*' => Http::response(['status' => 'active'], 200)]);
        $r = app(TwilioClient::class)->testConnection();
        $this->assertTrue($r['ok']);
        $this->assertStringNotContainsString(self::TOKEN, $r['message']);
        Http::assertSent(fn ($req) => $req->method() === 'GET' && str_ends_with($req->url(), '/Accounts/'.self::SID.'.json'));
        // 署名は URL＋キー順のパラメータ連結の HMAC-SHA1（Base64）。改ざんで不一致になる
        $params = ['CallSid' => 'CA1', 'Digits' => '1', 'From' => '+12349013030'];
        $sig = TwilioClient::sign('https://insights.example.jp/x', $params, self::TOKEN);
        $expected = base64_encode(hash_hmac('sha1', 'https://insights.example.jp/xCallSidCA1Digits1From+12349013030', self::TOKEN, true));
        $this->assertSame($expected, $sig);
        $this->assertTrue(app(TwilioClient::class)->validSignature('https://insights.example.jp/x', array_reverse($params, true), $sig), '順序に依存しない');
        $this->assertFalse(app(TwilioClient::class)->validSignature('https://insights.example.jp/x', ['Digits' => '2'] + $params, $sig));
    }

    public function test_test_call_is_guarded_by_env_and_audited_and_contacts_are_normalized(): void
    {
        $this->assertSame('+819012345678', \App\Monitoring\Services\ContactService::normalizePhone('090-1234-5678'));
        $this->assertSame('+819012345678', \App\Monitoring\Services\ContactService::normalizePhone('+81 90 1234 5678'));
        try {
            \App\Monitoring\Services\ContactService::normalizePhone('12-34');
            $this->fail();
        } catch (\InvalidArgumentException) {
        }
        $this->configurePhone();
        $c = Contact::query()->orderBy('order_no')->first();
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'CA'.str_repeat('7', 32)], 201)]);
        config()->set('monitoring.deployment.environment', 'staging');
        $r = app(\App\Monitoring\Services\NotificationTestService::class)->run('phone', self::ACTOR, (int) $c->id);
        $this->assertFalse($r['ok'], '許可されていない環境ではテスト発信もしない');
        $this->assertCount(0, $this->twilioCalls());
        config()->set('monitoring.deployment.environment', 'production');
        $r = app(\App\Monitoring\Services\NotificationTestService::class)->run('phone', self::ACTOR, (int) $c->id);
        $this->assertTrue($r['ok']);
        $this->assertStringNotContainsString('11112222', $r['message'].'x', '番号は伏字');
        $this->assertCount(1, $this->twilioCalls());
        $this->assertSame(2, AuditEvent::query()->where('action', 'notify.test.phone')->count());
        $token = $this->tokenOf($this->twilioCalls()[0]);
        $this->assertStringContainsString('テスト発信', $this->voice($token, '', ['CallSid' => 'CA'.str_repeat('7', 32)])->getContent());
        $saved = app(\App\Monitoring\Services\ContactService::class)->save(null, ['name' => '鈴木', 'phone' => '080-9999-0000'], self::ACTOR, '追加');
        $this->assertSame('+818099990000', $saved->phone_e164);
        $this->assertStringNotContainsString('9999-0000', json_encode(AuditEvent::query()->where('action', 'contact.create')->sole()->toArray()));
    }

    /** config:cache 後は env() が null になる。秘密値は config の写し（secret_values）から読めること（本番の必須条件） */
    public function test_secrets_resolve_from_config_copy_when_env_is_unavailable(): void
    {
        putenv('MONITOR_SLACK_ERROR_WEBHOOK');
        unset($_ENV['MONITOR_SLACK_ERROR_WEBHOOK'], $_SERVER['MONITOR_SLACK_ERROR_WEBHOOK']);
        $this->assertNull(env('MONITOR_SLACK_ERROR_WEBHOOK'));
        config()->set('monitoring.secret_values', ['MONITOR_SLACK_ERROR_WEBHOOK' => 'https://hooks.slack.com/services/T/B/cached', 'TWILIO_AUTH_TOKEN' => 'tok-from-config', 'MONITOR_COLLECTOR_SECRET_TRADE_WEB1' => 'col-secret']);
        $this->assertSame('https://hooks.slack.com/services/T/B/cached', $this->secrets()->get('slack.webhook_url'));
        $this->assertSame('env', $this->secrets()->source('slack.webhook_url'));
        $this->assertSame('tok-from-config', $this->secrets()->get('twilio.auth_token'));
        Http::fake(['hooks.slack.com/*' => Http::response('ok', 200)]);
        app(ObservationIngestor::class)->ingestInternal([$this->event()]);
        $this->assertSame(1, $this->dispatch()['sent']);
        Http::assertSent(fn ($r) => $r->url() === 'https://hooks.slack.com/services/T/B/cached');

        // 収集器の HMAC も config の写しで検証される
        $uuid = '7c7a2f6e-1111-4e8f-9b7e-000000000001';
        config()->set('monitoring.collectors', [['collector_uuid' => $uuid, 'application_code' => 'TRADE', 'node_code' => 'web1', 'secret_env' => 'MONITOR_COLLECTOR_SECRET_TRADE_WEB1', 'log_files' => []]]);
        app(\App\Monitoring\Services\SiteRegistry::class)->forget();
        $body = json_encode(['collector_uuid' => $uuid, 'events' => [$this->event(['scope' => ['fp' => 'c']])]]);
        $ts = (string) time();
        $this->call('POST', '/internal/monitor/events', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_MONITOR_COLLECTOR' => $uuid, 'HTTP_X_MONITOR_TIMESTAMP' => $ts, 'HTTP_X_MONITOR_SIGNATURE' => hash_hmac('sha256', $ts.'.'.$body, 'col-secret')], $body)->assertOk();
    }

    /** 同じ担当者へ同時に複数回かけない: 2〜4 件の連続エラーも 1 列にまとめ、途中で来た案件は進行中の列へ合流する */
    public function test_phone_never_runs_two_columns_at_once(): void
    {
        $this->configurePhone();
        $this->travelTo(CarbonImmutable::parse('2026-11-04 01:00', 'Asia/Tokyo'));
        Http::fake(['hooks.slack.com/*' => Http::response('ok', 200), 'api.twilio.com/*' => Http::response(['sid' => 'CA'.str_repeat('8', 32)], 201)]);
        app(ObservationIngestor::class)->ingestInternal([$this->event(['scope' => ['fp' => 'a']]), $this->event(['scope' => ['fp' => 'b']])]);
        $this->dispatch();
        $this->assertCount(1, $this->twilioCalls(), '2 件でも発信は 1 本');
        $lead = Notification::query()->where('route_key', 'phone')->where('status', 'RETRY_WAIT')->sole();
        $batch = \App\Models\Monitoring\DeliveryBatch::query()->find($lead->delivery_batch_id);
        $this->assertCount(2, $batch->notification_ids_json);

        // 発信中に 3 件目が来る → 進行中の列へ合流（新しい発信は起きない）
        app(ObservationIngestor::class)->ingestInternal([$this->event(['scope' => ['fp' => 'c']])]);
        $this->dispatch(CarbonImmutable::now('UTC')->addSeconds(10));
        $this->assertCount(1, $this->twilioCalls());
        $this->assertCount(3, $batch->fresh()->notification_ids_json);
        $this->assertSame(2, Notification::query()->where('route_key', 'phone')->where('cancel_reason', 'merged_into_call:'.$lead->id)->count());

        // 1 回目が応答なし → 2 分後に次の担当者へ 1 本だけ。読み上げは 3 件分
        $this->voice($this->tokenOf($this->twilioCalls()[0]), '/status', ['CallSid' => 'CA'.str_repeat('8', 32), 'CallStatus' => 'no-answer'])->assertNoContent();
        $this->dispatch(CarbonImmutable::now('UTC')->addSeconds(125));
        $this->assertCount(2, $this->twilioCalls());
        $tw = $this->voice($this->tokenOf($this->twilioCalls()[1]), '', ['CallSid' => 'CA'.str_repeat('8', 32)]);
        $this->assertStringContainsString('3 件', $tw->getContent());
        $this->voice($this->tokenOf($this->twilioCalls()[1]), '/gather', ['CallSid' => 'CA'.str_repeat('8', 32), 'Digits' => '1'])->assertOk();
        $this->assertSame(3, Incident::query()->where('workflow_status', 'ACKNOWLEDGED')->count(), '合流した案件も確認済みになる');
        $this->dispatch(CarbonImmutable::now('UTC')->addSeconds(130));
        $this->assertSame('DELIVERED', $lead->fresh()->status->value);
        $this->assertSame('DELIVERED', $batch->fresh()->status);
        $this->assertNotNull($batch->fresh()->expires_at, 'まとめは保持期限付きで閉じる');
    }

    public function test_phone_waits_when_all_contacts_are_off_hours(): void
    {
        $this->configurePhone();
        Contact::query()->update(['active_from' => '23:00', 'active_to' => '06:00']);
        $this->travelTo(CarbonImmutable::parse('2026-11-04 22:30', 'Asia/Tokyo'));
        Http::fake(['hooks.slack.com/*' => Http::response('ok', 200), 'api.twilio.com/*' => Http::response(['sid' => 'CA'.str_repeat('9', 32)], 201)]);
        app(ObservationIngestor::class)->ingestInternal([$this->event()]);
        $this->dispatch();
        $n = Notification::query()->where('route_key', 'phone')->sole();
        $this->assertSame('RETRY_WAIT', $n->status->value, '在席時間外は取り消さず待つ');
        $this->assertCount(0, $this->twilioCalls());
        $this->travelTo(CarbonImmutable::parse('2026-11-04 23:05', 'Asia/Tokyo'));
        $this->dispatch();
        $this->assertCount(1, $this->twilioCalls(), '在席になったら発信');
    }

    // ------------------------------------------------------------------ 多発防止

    private function twilioOk(string $sid = '1'): void
    {
        Http::fake(['hooks.slack.com/*' => Http::response('ok', 200), 'api.twilio.com/*' => Http::response(['sid' => 'CA'.str_repeat($sid, 32)], 201)]);
    }

    public function test_phone_delay_skips_call_when_incident_recovers_or_is_acknowledged_in_time(): void
    {
        $this->configurePhone();
        config()->set('monitoring.phone.delay_minutes', 5);
        $this->travelTo(CarbonImmutable::parse('2026-11-04 01:00', 'Asia/Tokyo'));
        $this->twilioOk();
        app(ObservationIngestor::class)->ingestInternal([$this->event(['source_kind' => 'CHECK', 'check_key' => 'trade.coupon_daily', 'outcome' => 'VIOLATION', 'run_id' => 1, 'business_date' => '2026-11-03', 'scope' => ['business_date' => '2026-11-03'], 'error_code' => null])]);
        $this->dispatch();
        $this->assertCount(0, $this->twilioCalls(), '猶予の間は発信しない');
        $this->assertSame('PENDING', Notification::query()->where('route_key', 'phone')->sole()->status->value);
        // 3 分後に復旧 → 5 分後の判定で取消
        $this->travel(3)->minutes();
        app(ObservationIngestor::class)->ingestInternal([$this->event(['source_kind' => 'CHECK', 'check_key' => 'trade.coupon_daily', 'outcome' => 'HEALTHY', 'severity' => 'INFO', 'run_id' => 2, 'business_date' => '2026-11-03', 'scope' => ['business_date' => '2026-11-03'], 'error_code' => null, 'verification_complete' => true])]);
        app(ObservationIngestor::class)->ingestInternal([$this->event(['source_kind' => 'CHECK', 'check_key' => 'trade.coupon_daily', 'outcome' => 'HEALTHY', 'severity' => 'INFO', 'run_id' => 3, 'business_date' => '2026-11-03', 'scope' => ['business_date' => '2026-11-03'], 'error_code' => null, 'verification_complete' => true])]);
        $this->assertSame('RECOVERED', Incident::sole()->observation_state->value);
        $this->travel(3)->minutes();
        $this->dispatch();
        $this->assertCount(0, $this->twilioCalls());
        $this->assertSame('recovered_before_call', Notification::query()->where('route_key', 'phone')->sole()->cancel_reason);

        // 別の案件: 猶予内に画面で確認 → 発信しない
        app(ObservationIngestor::class)->ingestInternal([$this->event(['scope' => ['fp' => 'x']])]);
        $i = Incident::query()->orderByDesc('id')->first();
        app(\App\Monitoring\Services\IncidentService::class)->act($i, 'acknowledge', (int) $i->version, ['id' => 9, 'name' => '担当'], [], 'k');
        $this->travel(6)->minutes();
        $this->dispatch();
        $this->assertCount(0, $this->twilioCalls());
        // 猶予を過ぎても未対応なら発信
        app(ObservationIngestor::class)->ingestInternal([$this->event(['scope' => ['fp' => 'y']])]);
        $this->travel(6)->minutes();
        $this->dispatch();
        $this->assertCount(1, $this->twilioCalls());
    }

    public function test_phone_cooldown_merges_new_incidents_into_one_later_call(): void
    {
        $this->configurePhone();
        config()->set('monitoring.phone.cooldown_minutes', 30);
        $this->travelTo(CarbonImmutable::parse('2026-11-04 01:00', 'Asia/Tokyo'));
        $this->twilioOk();
        app(ObservationIngestor::class)->ingestInternal([$this->event(['scope' => ['fp' => 'a']])]);
        $this->dispatch();
        $this->assertCount(1, $this->twilioCalls());
        $this->voice($this->tokenOf($this->twilioCalls()[0]), '/gather', ['CallSid' => 'CA'.str_repeat('1', 32), 'Digits' => '1'])->assertOk();
        $this->dispatch(CarbonImmutable::now('UTC')->addSeconds(5));
        $this->assertSame('DELIVERED', Notification::query()->where('route_key', 'phone')->orderBy('id')->first()->status->value);

        // 10 分後と 20 分後に別案件 → クールダウン中は発信せず、終了後に 1 本にまとまる
        $this->travel(10)->minutes();
        app(ObservationIngestor::class)->ingestInternal([$this->event(['scope' => ['fp' => 'b']])]);
        $this->dispatch();
        $this->travel(10)->minutes();
        app(ObservationIngestor::class)->ingestInternal([$this->event(['scope' => ['fp' => 'c']])]);
        $this->dispatch();
        $this->assertCount(1, $this->twilioCalls(), 'クールダウン中は発信しない');
        $this->travel(12)->minutes();
        $this->dispatch();
        $this->assertCount(2, $this->twilioCalls(), 'クールダウン後に 1 本');
        $tw = $this->voice($this->tokenOf($this->twilioCalls()[1]), '', ['CallSid' => 'CA'.str_repeat('1', 32)]);
        $this->assertStringContainsString('2 件', $tw->getContent());
    }

    public function test_phone_per_contact_cap_ends_column_instead_of_more_rounds(): void
    {
        $this->configurePhone();
        config()->set('monitoring.phone.max_calls_per_contact_per_window', 1);
        $this->travelTo(CarbonImmutable::parse('2026-11-04 01:00', 'Asia/Tokyo'));
        $this->twilioOk();
        app(ObservationIngestor::class)->ingestInternal([$this->event()]);
        $this->dispatch();
        $this->assertCount(1, $this->twilioCalls(), '山田');
        CallAttempt::query()->whereNull('finished_at')->update(['status' => 'no-answer', 'finished_at' => now('UTC')]);
        $this->travel(3)->minutes();
        $this->dispatch();
        $this->assertCount(2, $this->twilioCalls(), '佐藤');
        CallAttempt::query()->whereNull('finished_at')->update(['status' => 'no-answer', 'finished_at' => now('UTC')]);
        $this->travel(16)->minutes(); // 通常なら 2 巡目が始まる時刻
        $this->dispatch();
        $this->assertCount(2, $this->twilioCalls(), '全員が今夜の上限 → 2 巡目はかけない');
        $this->assertSame('UNANSWERED', Notification::query()->where('route_key', 'phone')->sole()->status->value);
    }

    public function test_phone_column_cap_per_window_stops_calls_but_not_slack(): void
    {
        $this->configurePhone();
        Contact::query()->where('order_no', 2)->delete();
        config()->set('monitoring.phone.max_columns_per_window', 2);
        config()->set('monitoring.phone.max_rounds', 1);
        $this->travelTo(CarbonImmutable::parse('2026-11-04 01:00', 'Asia/Tokyo'));
        $this->twilioOk();
        foreach (['a', 'b'] as $fp) {
            app(ObservationIngestor::class)->ingestInternal([$this->event(['scope' => ['fp' => $fp]])]);
            $this->dispatch();
            CallAttempt::query()->whereNull('finished_at')->update(['status' => 'no-answer', 'finished_at' => now('UTC')]);
            $this->travel(3)->minutes();
            $this->dispatch(); // 1 巡で終了 → UNANSWERED
        }
        $this->assertCount(2, $this->twilioCalls());
        $this->assertSame(2, Notification::query()->where('route_key', 'phone')->where('status', 'UNANSWERED')->count());
        app(ObservationIngestor::class)->ingestInternal([$this->event(['scope' => ['fp' => 'c']])]);
        $this->dispatch();
        $this->assertCount(2, $this->twilioCalls(), '今夜の列数上限に達したら発信しない');
        $this->assertSame('phone_capped', Notification::query()->where('route_key', 'phone')->orderByDesc('id')->first()->cancel_reason);
        $this->assertSame('DELIVERED', Notification::query()->where('route_key', 'error_channel')->orderByDesc('id')->first()->status->value, 'Slack は止まらない');
        $this->assertSame(2, app(PhoneNotifier::class)->windowStatus(CarbonImmutable::now('UTC'))['columns']);
    }

    public function test_same_incident_is_not_called_again_within_cooldown_hours(): void
    {
        $this->configurePhone();
        config()->set('monitoring.phone.per_incident_cooldown_hours', 12);
        $this->travelTo(CarbonImmutable::parse('2026-11-04 01:00', 'Asia/Tokyo'));
        $this->twilioOk();
        $q = app(\App\Monitoring\Services\NotificationQueue::class);
        app(ObservationIngestor::class)->ingestInternal([$this->event()]);
        $i = Incident::sole();
        $this->assertFalse($q->phoneWanted($i, \App\Monitoring\Enums\NotificationKind::RECURRENCE, CarbonImmutable::now('UTC')), '1 時間以内の再発では電話しない');
        $this->assertTrue($q->phoneWanted($i, \App\Monitoring\Enums\NotificationKind::RECURRENCE, CarbonImmutable::now('UTC')->addHours(24)), '翌晩（12 時間経過後）は電話できる');
    }

    public function test_mail_and_slack_hourly_caps_hold_notifications_instead_of_dropping(): void
    {
        Mail::fake();
        $this->configureSmtp();
        config()->set('monitoring.mail.max_per_hour', 1);
        config()->set('monitoring.mail.min_interval_minutes', 0);
        config()->set('monitoring.notification.max_per_hour', 1);
        app(NotificationSettings::class)->forget();
        Http::fake(['hooks.slack.com/*' => Http::response('ok', 200)]);
        $now = CarbonImmutable::parse('2026-11-04 10:00:00', 'UTC');
        $this->travelTo($now);
        app(ObservationIngestor::class)->ingestInternal([$this->event(['scope' => ['fp' => 'a']])]);
        $this->dispatch($now);
        app(ObservationIngestor::class)->ingestInternal([$this->event(['scope' => ['fp' => 'b']])]);
        $this->dispatch($now->addMinutes(10));
        $this->assertSame(1, Notification::query()->where('route_key', 'mail')->where('status', 'DELIVERED')->count(), '1 時間に 1 通まで');
        $this->assertSame(1, Notification::query()->where('route_key', 'error_channel')->where('status', 'DELIVERED')->count());
        $this->assertSame(2, Notification::query()->where('status', 'PENDING')->count(), '超過分は捨てず保留');
        $this->dispatch($now->addHours(1)->addMinute());
        $this->assertSame(2, Notification::query()->where('route_key', 'mail')->where('status', 'DELIVERED')->count(), '次の時間枠で配送');
        $this->assertSame(0, Notification::query()->where('status', 'PENDING')->count());
    }

    // ------------------------------------------------------------------ アラートの一時停止

    public function test_pause_holds_all_channels_and_resume_sends_digest_without_calling_for_handled_incidents(): void
    {
        Mail::fake();
        $this->configureSmtp();
        $this->configurePhone();
        $this->travelTo(CarbonImmutable::parse('2026-11-04 01:00', 'Asia/Tokyo'));
        $this->twilioOk();
        $settings = app(NotificationSettings::class);
        $p = $settings->pauseAlerts(60, self::ACTOR, 'バッチ障害の対応中');
        $this->assertSame('02:00', $p['until']->setTimezone('Asia/Tokyo')->format('H:i'));
        $this->assertTrue(AuditEvent::query()->where('action', 'notify.pause')->exists());

        app(ObservationIngestor::class)->ingestInternal([$this->event(['scope' => ['fp' => 'a']]), $this->event(['scope' => ['fp' => 'b']])]);
        $stats = $this->dispatch();
        $this->assertSame(-1, $stats['skipped'], '停止中は配送しない');
        $this->assertSame(0, Notification::query()->where('status', 'DELIVERED')->count());
        Http::assertNothingSent();
        Mail::assertNothingSent();
        $this->assertSame(6, Notification::query()->where('status', 'PENDING')->count(), 'Slack・メール・電話 × 2 件が保留');

        // 停止中に 1 件を対応開始 → 再開後、その案件の電話はかけない。Slack・メールはまとめて送る
        $a = Incident::query()->orderBy('id')->first();
        app(\App\Monitoring\Services\IncidentService::class)->act($a, 'start', (int) $a->version, ['id' => 9, 'name' => '担当'], ['reason' => '対応中'], 'k');
        $this->travel(61)->minutes();
        $this->assertNull($settings->pause(), '期限で自動再開');
        $this->dispatch();
        $this->assertSame(2, Notification::query()->where('route_key', 'error_channel')->where('status', 'DELIVERED')->count());
        Http::assertSentCount(3); // Slack 2 通（3 件以下は個別）＋ Twilio 1 本
        $this->assertCount(1, $this->twilioCalls(), '未対応の 1 件だけ 1 本');
        $this->assertSame('acknowledged_elsewhere', Notification::query()->where('route_key', 'phone')->where('incident_id', $a->id)->sole()->cancel_reason);

        // 手動再開と権限・入力の検証
        $settings->pauseAlerts(30, self::ACTOR, '再度');
        $this->assertNotNull($settings->pause());
        $settings->resumeAlerts(self::ACTOR);
        $this->assertNull($settings->pause());
        $this->expectException(\InvalidArgumentException::class);
        $settings->pauseAlerts(1000, self::ACTOR, '長すぎ');
    }
}
