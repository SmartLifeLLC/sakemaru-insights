<?php

namespace App\Monitoring\Support;

use Carbon\CarbonImmutable;

/** 画面表示用の小さなヘルパ（JST 表示・相対時間・バッジ種別）。 */
final class Ui
{
    public static function jst(mixed $value, string $format = 'm/d H:i'): string
    {
        if (! $value) {
            return '—';
        }

        return CarbonImmutable::instance($value instanceof \DateTimeInterface ? $value : CarbonImmutable::parse((string) $value, 'UTC'))->setTimezone('Asia/Tokyo')->format($format);
    }

    public static function jstFull(mixed $value): string
    {
        return self::jst($value, 'Y/m/d H:i:s');
    }

    public static function ago(mixed $value, ?CarbonImmutable $now = null): string
    {
        if (! $value) {
            return '—';
        }
        $now ??= CarbonImmutable::now('UTC');
        $t = CarbonImmutable::instance($value instanceof \DateTimeInterface ? $value : CarbonImmutable::parse((string) $value, 'UTC'));
        $s = (int) $t->diffInSeconds($now, false);
        if ($s < 0) {
            $s = -$s;
            if ($s < 60) {
                return "{$s}秒後";
            }
            if ($s < 3600) {
                return intdiv($s, 60).'分後';
            }
            if ($s < 86400) {
                return intdiv($s, 3600).'時間後';
            }

            return intdiv($s, 86400).'日後';
        }
        if ($s < 60) {
            return "{$s}秒前";
        }
        if ($s < 3600) {
            return intdiv($s, 60).'分前';
        }
        if ($s < 86400) {
            return intdiv($s, 3600).'時間'.(intdiv($s % 3600, 60)).'分前';
        }

        return intdiv($s, 86400).'日前';
    }

    public static function duration(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}秒";
        }
        if ($seconds < 3600) {
            return intdiv($seconds, 60).'分';
        }
        if ($seconds < 86400) {
            return intdiv($seconds, 3600).'時間'.intdiv($seconds % 3600, 60).'分';
        }

        return intdiv($seconds, 86400).'日'.intdiv($seconds % 86400, 3600).'時間';
    }

    /** 重大度・結果・状態 → CSS トーン（critical/error/warning/ok/unknown/info/neutral） */
    public static function tone(mixed $value): string
    {
        $v = $value instanceof \BackedEnum ? $value->value : (string) $value;

        return match (strtoupper($v)) {
            'CRITICAL' => 'critical',
            'ERROR', 'VIOLATION', 'BLOCKED', 'FAILED', 'TIMED_OUT', 'ACTIVE' => 'error',
            'WARNING', 'RETRY_WAIT', 'STALE', 'UNKNOWN', 'BUDGET_WAIT', 'IN_PROGRESS', 'ACKNOWLEDGED' => 'warning',
            'HEALTHY', 'DELIVERED', 'SUCCEEDED', 'RECOVERED', 'RESOLVED', 'CONFIGURED', 'OK', 'DONE' => 'ok',
            'OPEN' => 'critical',
            'INFO', 'NOT_DUE', 'PENDING', 'SENDING', 'QUEUED', 'RUNNING' => 'info',
            default => 'neutral',
        };
    }

    public static function label(mixed $value): string
    {
        if ($value instanceof \BackedEnum && method_exists($value, 'label')) {
            return $value->label();
        }
        $v = $value instanceof \BackedEnum ? $value->value : (string) $value;

        return match (strtoupper($v)) {
            'OPEN' => '未確認', 'ACKNOWLEDGED' => '確認済', 'IN_PROGRESS' => '対応中', 'RESOLVED' => '解決', 'CLOSED' => '終了',
            'ACTIVE' => '継続中', 'RECOVERED' => '復旧', 'UNKNOWN' => '確認不能',
            'PENDING' => '送信待ち', 'SENDING' => '送信中', 'RETRY_WAIT' => '再試行待ち', 'DELIVERED' => '送信済', 'BLOCKED' => '配送不可', 'CANCELLED' => '取消',
            'HEALTHY' => '正常', 'VIOLATION' => '違反', 'NOT_DUE' => '判定前', 'DISABLED' => '無効', 'WARNING' => 'ワーニング', 'ERROR' => 'エラー', 'CRITICAL' => '重大', 'INFO' => '情報',
            'QUEUED' => '予約済', 'RUNNING' => '実行中', 'DONE' => '完了', 'BUDGET_WAIT' => '予算待ち', 'FAILED' => '失敗', 'SUCCEEDED' => '完了', 'TIMED_OUT' => 'タイムアウト',
            'CONFIGURED' => '監視中', 'NOT_CONFIGURED' => '未監視', 'STALE' => '更新停止',
            'CHECK' => 'チェック', 'APPLICATION_LOG' => 'アプリログ', 'MONITOR_SYSTEM' => '監視システム',
            default => $v === '' ? '—' : $v,
        };
    }
}
