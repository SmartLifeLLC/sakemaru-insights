<?php

namespace App\Monitoring\Support;

/**
 * 秘密情報・個人情報の伏字（仕様書 17）。収集器と取込側の両方で適用する。
 * 伏字失敗時に生値を別ログへ書かない。
 */
final class Redactor
{
    private const MASK = '[REDACTED]';

    /** @var array<string> 値を丸ごと伏字にするキー名（大小無視・部分一致） */
    private const SECRET_KEYS = [
        'password', 'passwd', 'secret', 'token', 'authorization', 'cookie', 'webhook', 'api_key', 'apikey',
        'private_key', 'credential', 'db_password', 'dsn', 'session', 'card_number', 'cvv', 'remember_token',
    ];

    public static function text(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }
        $t = $text;
        // Slack / generic webhook URL
        $t = preg_replace('#https?://hooks\.slack\.com/[^\s"\']*#i', 'https://hooks.slack.com/'.self::MASK, $t);
        // Authorization / Cookie headers
        $t = preg_replace('/(authorization\s*[:=]\s*)(bearer\s+)?[^\s,;"\']+/i', '$1'.self::MASK, $t);
        $t = preg_replace('/((?:set-)?cookie\s*[:=]\s*)[^\n]+/i', '$1'.self::MASK, $t);
        // key=value / "key":"value" 形式の秘密
        $keys = implode('|', array_map('preg_quote', self::SECRET_KEYS));
        $t = preg_replace('/((?:'.$keys.')[a-z0-9_\-]*\s*["\']?\s*[:=]\s*["\']?)([^\s"\',;&}]+)/i', '$1'.self::MASK, $t);
        // DB DSN (mysql://user:pass@host)
        $t = preg_replace('#((?:mysql|pgsql|redis|amqp|mongodb)://)[^\s/@]+@#i', '$1'.self::MASK.'@', $t);
        // メールアドレス
        $t = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email]', $t);
        // クレジットカード風 13-19 桁
        $t = preg_replace('/\b(?:\d[ -]?){13,19}\b/', '[card]', $t);
        // 電話番号（日本）
        $t = preg_replace('/(?<![\w-])0\d{1,4}-\d{1,4}-\d{4}(?![\w-])/', '[tel]', $t);
        $t = preg_replace('/(?<![\w-])0[789]0\d{8}(?![\w-])/', '[tel]', $t);

        return $t;
    }

    /** @param array<mixed> $data */
    public static function array(array $data, int $depth = 0): array
    {
        if ($depth > 8) {
            return [self::MASK];
        }
        $out = [];
        foreach ($data as $k => $v) {
            $key = (string) $k;
            if (self::isSecretKey($key)) {
                $out[$k] = self::MASK;

                continue;
            }
            if (is_array($v)) {
                $out[$k] = self::array($v, $depth + 1);
            } elseif (is_string($v)) {
                $out[$k] = self::text($v);
            } else {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    public static function isSecretKey(string $key): bool
    {
        $k = strtolower($key);
        foreach (self::SECRET_KEYS as $s) {
            if (str_contains($k, $s)) {
                return true;
            }
        }

        return false;
    }

    /** 文字列を最大バイト数に切り詰め、切り詰めたことを明記する。 */
    public static function truncate(string $text, int $maxBytes): string
    {
        if (strlen($text) <= $maxBytes) {
            return $text;
        }
        $cut = mb_strcut($text, 0, max(0, $maxBytes - 24));

        return $cut.' …[truncated '.(strlen($text) - strlen($cut)).'B]';
    }
}
