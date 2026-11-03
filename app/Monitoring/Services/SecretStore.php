<?php

namespace App\Monitoring\Services;

use App\Models\Monitoring\Secret;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;

/**
 * 通知経路の接続情報（v2 第7.3 / V-161〜166）。値は Crypt（APP_KEY）で暗号化して保存し、平文を DB・画面・ログ・監査に出さない。
 * 優先順位: 画面の設定 > .env。どちらも無ければ null（経路は「未設定」扱い）。
 */
class SecretStore
{
    /** secret_key => [表示名, .env のキー, 秘密か（伏字）] */
    public const KEYS = [
        'smtp.host' => ['SMTP サーバー', 'MAIL_HOST', false],
        'smtp.port' => ['SMTP ポート', 'MAIL_PORT', false],
        'smtp.username' => ['SMTP ユーザー', 'MAIL_USERNAME', false],
        'smtp.password' => ['SMTP パスワード', 'MAIL_PASSWORD', true],
        'smtp.encryption' => ['SMTP 暗号化（tls / ssl / なし）', 'MAIL_ENCRYPTION', false],
        'smtp.from_address' => ['送信元アドレス', 'MAIL_FROM_ADDRESS', false],
        'smtp.from_name' => ['送信元名', 'MAIL_FROM_NAME', false],
        'twilio.account_sid' => ['Twilio Account SID', 'TWILIO_ACCOUNT_SID', true],
        'twilio.auth_token' => ['Twilio Auth Token', 'TWILIO_AUTH_TOKEN', true],
        'twilio.from_number' => ['Twilio 発信元番号', 'TWILIO_FROM_NUMBER', false],
        'slack.webhook_url' => ['Slack Webhook URL（任意）', 'MONITOR_SLACK_ERROR_WEBHOOK', true],
    ];

    /** @var array<string,?string>|null */
    private ?array $cache = null;

    public function __construct(private readonly AuditService $audit) {}

    private function load(): array
    {
        if ($this->cache === null) {
            $this->cache = [];
            foreach (Secret::query()->get(['secret_key', 'value_encrypted']) as $row) {
                try {
                    $this->cache[$row->secret_key] = Crypt::decryptString($row->value_encrypted);
                } catch (\Throwable) {
                    $this->cache[$row->secret_key] = null; // APP_KEY 変更などで復号不能。値は出さない
                }
            }
        }

        return $this->cache;
    }

    public function forget(): void
    {
        $this->cache = null;
    }

    /** 復号した値（画面 > .env）。呼び出し側は値をログ・例外・画面に出さないこと。 */
    public function get(string $key): ?string
    {
        $this->assertKey($key);
        $v = $this->load()[$key] ?? null;
        if ($v !== null && $v !== '') {
            return $v;
        }

        return \App\Monitoring\Support\Env::get(self::KEYS[$key][1]);
    }

    /** 'screen' / 'env' / null（未設定） */
    public function source(string $key): ?string
    {
        $this->assertKey($key);
        $v = $this->load()[$key] ?? null;
        if ($v !== null && $v !== '') {
            return 'screen';
        }

        return \App\Monitoring\Support\Env::get(self::KEYS[$key][1]) === null ? null : 'env';
    }

    /** 画面表示用。秘密値は末尾 4 文字以外を伏字。秘密でない値はそのまま。 */
    public function display(string $key): ?string
    {
        $v = $this->get($key);
        if ($v === null) {
            return null;
        }

        return self::KEYS[$key][2] ? self::mask($v) : $v;
    }

    public static function mask(string $v): string
    {
        $len = mb_strlen($v);

        return $len <= 4 ? str_repeat('•', $len) : str_repeat('•', min(12, $len - 4)).mb_substr($v, -4);
    }

    /**
     * 一括保存。空欄（null / ''）の項目は変更しない。clear に入れた項目は画面の設定を消す（.env へ戻る）。
     * 監査には「変更あり」の項目名だけを残し、値は記録しない。
     *
     * @param  array<string,?string>  $values
     * @param  string[]  $clear
     * @return string[] 変更した項目
     */
    public function save(array $values, array $actor, string $reason, array $clear = []): array
    {
        if (mb_strlen(trim($reason)) < 2) {
            throw new \InvalidArgumentException('変更理由を入力してください。');
        }
        $changed = [];
        $now = CarbonImmutable::now('UTC');
        foreach ($values as $key => $value) {
            $this->assertKey($key);
            if ($value === null || $value === '') {
                continue;
            }
            $value = trim((string) $value);
            $this->validate($key, $value);
            Secret::query()->updateOrCreate(['secret_key' => $key], ['value_encrypted' => Crypt::encryptString($value),
                'updated_user_id' => $actor['id'] ?? null, 'updated_name' => $actor['name'] ?? null, 'updated_at' => $now]);
            $changed[] = $key;
        }
        foreach ($clear as $key) {
            $this->assertKey($key);
            if (Secret::query()->where('secret_key', $key)->delete()) {
                $changed[] = $key.'（画面の設定を削除）';
            }
        }
        if ($changed) {
            $this->audit->record($actor, 'secrets.update', 'notification_settings', 'secrets', null, ['changed' => $changed], $reason);
        }
        $this->forget();

        return $changed;
    }

    private function validate(string $key, string $v): void
    {
        $ok = match ($key) {
            'smtp.port' => ctype_digit($v) && (int) $v > 0 && (int) $v < 65536,
            'smtp.encryption' => in_array(strtolower($v), ['tls', 'ssl', 'none', 'starttls'], true),
            'smtp.from_address' => (bool) filter_var($v, FILTER_VALIDATE_EMAIL),
            'twilio.account_sid' => (bool) preg_match('/^AC[0-9a-fA-F]{32}$/', $v),
            'twilio.from_number' => (bool) preg_match('/^\+[1-9]\d{6,14}$/', $v),
            'slack.webhook_url' => str_starts_with($v, 'https://hooks.slack.com/'),
            default => mb_strlen($v) <= 500,
        };
        if (! $ok) {
            throw new \InvalidArgumentException(self::KEYS[$key][0].' の形式が正しくありません。');
        }
    }

    private function assertKey(string $key): void
    {
        if (! isset(self::KEYS[$key])) {
            throw new \InvalidArgumentException('unknown_secret_key');
        }
    }

    public function smtpConfigured(): bool
    {
        return $this->get('smtp.host') !== null && $this->get('smtp.from_address') !== null;
    }

    public function twilioConfigured(): bool
    {
        return $this->get('twilio.account_sid') !== null && $this->get('twilio.auth_token') !== null && $this->get('twilio.from_number') !== null;
    }
}
