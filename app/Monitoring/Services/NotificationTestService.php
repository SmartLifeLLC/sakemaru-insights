<?php

namespace App\Monitoring\Services;

use App\Models\Monitoring\Contact;
use App\Monitoring\Enums\NotificationKind;
use App\Monitoring\Enums\Severity;
use App\Monitoring\Notify\MailNotifier;
use App\Monitoring\Notify\PhoneNotifier;
use App\Monitoring\Notify\TwilioClient;
use Carbon\CarbonImmutable;

/**
 * 接続テスト・テスト送信（v2 V-120 / V-159 / V-165）。画面では monitor.delivery_admin だけが呼べる。結果は監査に残し、秘密値は含めない。
 */
class NotificationTestService
{
    public function __construct(
        private readonly MailNotifier $mail,
        private readonly TwilioClient $twilio,
        private readonly PhoneNotifier $phone,
        private readonly NotificationQueue $queue,
        private readonly SiteRegistry $sites,
        private readonly AuditService $audit,
    ) {}

    /** @return array{ok:bool,message:string} */
    public function run(string $kind, array $actor, ?int $contactId = null): array
    {
        $now = CarbonImmutable::now('UTC');
        $r = match ($kind) {
            'smtp_connection' => $this->mail->testConnection(),
            'mail' => $this->mailTest(),
            'twilio_connection' => $this->twilio->testConnection(),
            'phone' => $this->phoneTest($contactId, $now),
            'slack' => $this->slackTest($now),
            default => throw new \InvalidArgumentException('unknown_test'),
        };
        $this->audit->record($actor, 'notify.test.'.$kind, 'notification_settings', $kind, null, ['ok' => $r['ok'], 'message' => $r['message'], 'contact_id' => $contactId], 'テスト実行');

        return $r;
    }

    private function mailTest(): array
    {
        if (! $this->mail->configured()) {
            return ['ok' => false, 'message' => 'SMTP の設定または宛先が未登録です。'];
        }
        $subject = '['.$this->sites->clientName().' '.$this->sites->environmentLabel().']['.$this->sites->label('INSIGHTS').'] テスト送信';
        $r = $this->mail->send($subject, "算盤 監視からのテスト送信です。\nこのメールが届いていれば、エラー発生時のメール通知は正しく設定されています。");

        return $r['ok'] ? ['ok' => true, 'message' => implode(', ', $this->mail->recipients()).' へ送信しました。'] : ['ok' => false, 'message' => '送信に失敗しました: '.$r['error']];
    }

    private function phoneTest(?int $contactId, CarbonImmutable $now): array
    {
        $c = $contactId ? Contact::query()->find($contactId) : null;
        if (! $c) {
            return ['ok' => false, 'message' => '発信先の担当者を選んでください。'];
        }

        return $this->phone->testCall($c, $now);
    }

    private function slackTest(CarbonImmutable $now): array
    {
        $n = $this->queue->enqueueSystem(NotificationKind::TEST, 'test:'.$now->getTimestamp(), ['title' => 'テスト通知', 'body' => '算盤 監視のテスト通知です。'], null, Severity::INFO);

        return $n ? ['ok' => true, 'message' => "通知待ちに積みました（N-{$n->id}）。1 分以内に配送されます。"] : ['ok' => false, 'message' => '通知待ちに積めませんでした。'];
    }
}
