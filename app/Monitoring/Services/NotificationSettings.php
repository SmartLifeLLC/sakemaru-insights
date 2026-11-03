<?php

namespace App\Monitoring\Services;

use App\Models\Monitoring\Setting;
use Carbon\CarbonImmutable;

/**
 * 通知の動作設定（秘密でない値）。既定は config/monitoring.php、画面で変えた値は ins_monitor_settings（setting_key = notify.mail / notify.phone）。
 */
class NotificationSettings
{
    private ?array $cache = null;

    public function __construct(private readonly AuditService $audit) {}

    public function mail(): array
    {
        return $this->merged('mail');
    }

    public function phone(): array
    {
        return $this->merged('phone');
    }

    private function merged(string $channel): array
    {
        $this->cache ??= Setting::query()->whereIn('setting_key', ['notify.mail', 'notify.phone'])->pluck('value_json', 'setting_key')->map(fn ($v) => (array) $v)->all();

        return array_merge((array) config('monitoring.'.$channel, []), $this->cache['notify.'.$channel] ?? []);
    }

    public function forget(): void
    {
        $this->cache = null;
    }

    private const EDITABLE = [
        'mail' => ['enabled', 'recipients', 'include_reminder', 'include_recovery', 'min_interval_minutes', 'max_per_hour'],
        'phone' => ['enabled', 'window_from', 'window_to', 'severities', 'delay_minutes', 'cooldown_minutes', 'per_incident_cooldown_hours', 'max_columns_per_window', 'max_calls_per_contact_per_window'],
    ];

    public function save(string $channel, array $values, array $actor, string $reason): array
    {
        if (! isset(self::EDITABLE[$channel])) {
            throw new \InvalidArgumentException('unknown_channel');
        }
        if (mb_strlen(trim($reason)) < 2) {
            throw new \InvalidArgumentException('変更理由を入力してください。');
        }
        $values = array_intersect_key($values, array_flip(self::EDITABLE[$channel]));
        if (isset($values['recipients'])) {
            $values['recipients'] = array_values(array_filter(array_map('trim', (array) $values['recipients'])));
            foreach ($values['recipients'] as $r) {
                if (! filter_var($r, FILTER_VALIDATE_EMAIL)) {
                    throw new \InvalidArgumentException("宛先 {$r} の形式が正しくありません。");
                }
            }
        }
        foreach (['window_from', 'window_to'] as $k) {
            if (isset($values[$k]) && ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $values[$k])) {
                throw new \InvalidArgumentException('時刻は HH:MM で入力してください。');
            }
        }
        foreach (['delay_minutes', 'cooldown_minutes', 'per_incident_cooldown_hours', 'max_columns_per_window', 'max_calls_per_contact_per_window', 'min_interval_minutes', 'max_per_hour'] as $k) {
            if (isset($values[$k])) {
                if (! is_numeric($values[$k]) || (int) $values[$k] < 0 || (int) $values[$k] > 10000) {
                    throw new \InvalidArgumentException("{$k} は 0 以上の整数で入力してください。");
                }
                $values[$k] = (int) $values[$k];
            }
        }
        if (isset($values['severities'])) {
            $values['severities'] = array_values(array_intersect(['ERROR', 'CRITICAL'], (array) $values['severities']));
        }
        $before = $this->merged($channel);
        $row = Setting::query()->firstOrNew(['setting_key' => 'notify.'.$channel]);
        $row->value_json = array_merge((array) $row->value_json, $values);
        $row->updated_user_id = $actor['id'] ?? null;
        $row->updated_name = $actor['name'] ?? null;
        $row->reason = $reason;
        $row->updated_at = CarbonImmutable::now('UTC');
        $row->save();
        $this->forget();
        $this->audit->record($actor, 'notify.'.$channel.'.update', 'notification_settings', $channel, array_intersect_key($before, $values), $values, $reason);

        return $this->merged($channel);
    }

    /** 夜間帯の判定（JST、日をまたぐ範囲に対応）。from <= t < to */
    public function inPhoneWindow(CarbonImmutable $at): bool
    {
        $p = $this->phone();
        $t = $at->setTimezone('Asia/Tokyo')->format('H:i');
        $from = (string) $p['window_from'];
        $to = (string) $p['window_to'];

        return $from <= $to ? ($t >= $from && $t < $to) : ($t >= $from || $t < $to);
    }

    public function phoneAllowedHere(): bool
    {
        $allow = (string) ($this->phone()['allow_env'] ?? 'production');

        return $allow !== '' && $allow === (string) config('monitoring.deployment.environment');
    }

    /** 現在の夜間帯の開始時刻（UTC）。帯の外なら直近に終わった帯の開始 */
    public function phoneWindowStart(CarbonImmutable $at): CarbonImmutable
    {
        $p = $this->phone();
        [$fh, $fm] = array_map('intval', explode(':', (string) $p['window_from']));
        $jst = $at->setTimezone('Asia/Tokyo');
        $start = $jst->setTime($fh, $fm);
        if ($start->greaterThan($jst)) {
            $start = $start->subDay();
        }

        return $start->utc();
    }

    // ------------------------------------------------------------------ アラートの一時停止（対応中は新規の通知を全て止める）

    /** @return array{until:?CarbonImmutable,reason:?string,actor_name:?string,started_at:?CarbonImmutable}|null 停止中でなければ null */
    public function pause(?CarbonImmutable $now = null): ?array
    {
        $now ??= CarbonImmutable::now('UTC');
        $row = Setting::query()->where('setting_key', 'notify.pause')->first();
        $v = (array) ($row?->value_json ?? []);
        if (empty($v['until']) || CarbonImmutable::parse($v['until'], 'UTC')->lessThanOrEqualTo($now)) {
            return null; // 期限で自動再開
        }

        return ['until' => CarbonImmutable::parse($v['until'], 'UTC'), 'reason' => $v['reason'] ?? null, 'actor_name' => $v['actor_name'] ?? null,
            'started_at' => isset($v['started_at']) ? CarbonImmutable::parse($v['started_at'], 'UTC') : null];
    }

    /** 新規アラートを minutes 分止める（Slack・メール・電話とも送らない。通知は保留し、再開後にまとめて送る）。監査あり */
    public function pauseAlerts(int $minutes, array $actor, string $reason): array
    {
        if (mb_strlen(trim($reason)) < 2) {
            throw new \InvalidArgumentException('停止の理由を入力してください。');
        }
        $max = (int) config('monitoring.notification.max_pause_minutes', 240);
        if ($minutes < 5 || $minutes > $max) {
            throw new \InvalidArgumentException("停止時間は 5〜{$max} 分で指定してください。");
        }
        $now = CarbonImmutable::now('UTC');
        $before = $this->pause($now);
        $value = ['until' => $now->addMinutes($minutes)->toIso8601String(), 'reason' => mb_substr($reason, 0, 500), 'actor_name' => $actor['name'] ?? null, 'actor_user_id' => $actor['id'] ?? null, 'started_at' => $now->toIso8601String()];
        $row = Setting::query()->firstOrNew(['setting_key' => 'notify.pause']);
        $row->value_json = $value;
        $row->updated_user_id = $actor['id'] ?? null;
        $row->updated_name = $actor['name'] ?? null;
        $row->reason = $reason;
        $row->updated_at = $now;
        $row->save();
        $this->audit->record($actor, 'notify.pause', 'notification_settings', 'pause', $before ? ['until' => $before['until']->toIso8601String()] : null, ['until' => $value['until'], 'minutes' => $minutes], $reason);

        return $this->pause($now);
    }

    public function resumeAlerts(array $actor, string $reason = '手動で再開'): void
    {
        $now = CarbonImmutable::now('UTC');
        $before = $this->pause($now);
        Setting::query()->where('setting_key', 'notify.pause')->update(['value_json' => json_encode(['until' => null, 'resumed_at' => $now->toIso8601String(), 'resumed_by' => $actor['name'] ?? null]), 'updated_user_id' => $actor['id'] ?? null, 'updated_name' => $actor['name'] ?? null, 'reason' => $reason, 'updated_at' => $now]);
        $this->audit->record($actor, 'notify.resume', 'notification_settings', 'pause', $before ? ['until' => $before['until']->toIso8601String()] : null, null, $reason);
    }
}
