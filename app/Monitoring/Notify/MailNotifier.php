<?php

namespace App\Monitoring\Notify;

use App\Mail\MonitorAlertMail;
use App\Monitoring\Services\NotificationSettings;
use App\Monitoring\Services\SecretStore;
use App\Monitoring\Support\Redactor;
use Illuminate\Support\Facades\Mail;

/**
 * メール通知（v2 第7.1）。SMTP 接続情報は SecretStore（画面 > .env）から実行時に組み立てる。
 * 失敗理由に接続情報（パスワード・ユーザー名）を含めない（V-166）。
 */
class MailNotifier
{
    public const MAILER = 'monitor';

    public function __construct(private readonly SecretStore $secrets, private readonly NotificationSettings $settings) {}

    public function configured(): bool
    {
        return $this->secrets->smtpConfigured() && $this->recipients();
    }

    /** @return string[] */
    public function recipients(): array
    {
        return array_values(array_filter((array) ($this->settings->mail()['recipients'] ?? []), fn ($r) => filter_var($r, FILTER_VALIDATE_EMAIL)));
    }

    private function configureMailer(): void
    {
        $enc = strtolower((string) $this->secrets->get('smtp.encryption'));
        $port = (int) ($this->secrets->get('smtp.port') ?: ($enc === 'ssl' ? 465 : 587));
        config()->set('mail.mailers.'.self::MAILER, [
            'transport' => 'smtp',
            'scheme' => $enc === 'ssl' ? 'smtps' : 'smtp',
            'host' => $this->secrets->get('smtp.host'),
            'port' => $port,
            'username' => $this->secrets->get('smtp.username'),
            'password' => $this->secrets->get('smtp.password'),
            'timeout' => (int) config('monitoring.mail.timeout_seconds', 15),
        ]);
        Mail::purge(self::MAILER);
    }

    /** @return array{ok:bool,permanent:bool,error:?string} */
    public function send(string $subject, string $body, ?array $to = null): array
    {
        $to ??= $this->recipients();
        if (! $to || ! $this->secrets->smtpConfigured()) {
            return ['ok' => false, 'permanent' => false, 'error' => 'not_configured'];
        }
        try {
            $this->configureMailer();
            Mail::mailer(self::MAILER)->to($to)->send(new MonitorAlertMail(
                mb_substr(str_replace(["\r", "\n"], ' ', $subject), 0, 200), $body,
                $this->secrets->get('smtp.from_address'), $this->secrets->get('smtp.from_name')));
        } catch (\Throwable $e) {
            $msg = $this->sanitize($e->getMessage());
            $permanent = (bool) preg_match('/\b(535|534|530|550|553|554)\b|authenticat/i', $msg);

            return ['ok' => false, 'permanent' => $permanent, 'error' => mb_substr($msg, 0, 200)];
        }

        return ['ok' => true, 'permanent' => false, 'error' => null];
    }

    /** 接続テスト（EHLO / AUTH まで。送信しない） @return array{ok:bool,message:string} */
    public function testConnection(): array
    {
        if (! $this->secrets->smtpConfigured()) {
            return ['ok' => false, 'message' => 'SMTP サーバーと送信元アドレスが未設定です。'];
        }
        try {
            $this->configureMailer();
            $transport = Mail::mailer(self::MAILER)->getSymfonyTransport();
            if (method_exists($transport, 'start')) {
                $transport->start();
                $transport->stop();
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => '接続に失敗しました: '.mb_substr($this->sanitize($e->getMessage()), 0, 160)];
        }

        return ['ok' => true, 'message' => 'SMTP サーバーに接続し、認証できました（送信はしていません）。'];
    }

    /** 例外文言から接続情報を除去する */
    private function sanitize(string $msg): string
    {
        foreach (['smtp.password', 'smtp.username', 'smtp.host'] as $k) {
            $v = $this->secrets->get($k);
            if ($v !== null && strlen($v) >= 3) {
                $msg = str_replace($v, $k === 'smtp.host' ? '[host]' : '[REDACTED]', $msg);
            }
        }
        $msg = preg_replace('/username\s+"[^"]*"/i', 'username "[REDACTED]"', $msg);

        return (string) Redactor::text($msg);
    }
}
