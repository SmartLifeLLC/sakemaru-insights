<?php

namespace App\Monitoring\Services;

use App\Models\Monitoring\Incident;
use App\Models\Monitoring\Notification;
use App\Models\Monitoring\Runbook;
use App\Models\Monitoring\Site;
use App\Monitoring\Enums\NotificationKind;
use App\Monitoring\Enums\ObservationState;
use App\Monitoring\Support\Redactor;

/**
 * 通知本文（仕様書 13.3）。外部入力由来の @channel / @here / メンションは無効化する。
 */
class SlackMessageBuilder
{
    public function __construct(private readonly SiteRegistry $sites) {}

    public function forNotification(Notification $n, ?Incident $incident, ?Site $site, ?Runbook $runbook): string
    {
        $client = $this->sites->clientName();
        $env = $this->sites->environmentLabel();
        if (! $incident) {
            $p = $n->payload_snapshot_json ?? [];

            return $this->sanitize("[{$client} {$env}][{$this->sites->label('INSIGHTS')}][{$n->severity}] ".($p['title'] ?? '算盤 監視からの通知')."\n".($p['body'] ?? '')."\n(算盤 監視 / 通知番号 N-{$n->id})");
        }
        $kindLabel = $n->kind->label();
        if ($n->kind === NotificationKind::NEW && $incident->observation_state === ObservationState::RECOVERED && $incident->generation === $n->generation) {
            $kindLabel .= '（検知後に復旧確認済み）';
        }
        $appLabel = $site ? $this->sites->label($site->application_code) : '?';
        $lines = [];
        $lines[] = "[{$client} {$env}][{$appLabel}][{$incident->severity->value}] {$incident->public_id} {$kindLabel}";
        $lines[] = $incident->title;
        $scopeLine = $this->scopeLine($incident);
        if ($scopeLine) {
            $lines[] = $scopeLine;
        }
        $metrics = $incident->evidence_snapshot_json['metrics'] ?? [];
        if ($metrics) {
            $lines[] = '指標: '.$this->metricsLine($metrics);
        }
        $lines[] = '発生: 初回 '.$this->jst($incident->first_seen_at).' / 最終 '.$this->jst($incident->last_seen_at).' / 回数 '.number_format((int) $incident->occurrence_count).($incident->generation > 1 ? " / 第{$incident->generation}世代" : '');
        $lines[] = '状態: '.$incident->workflow_status->label().' / 観測: '.$incident->observation_state->label().' / 担当: '.($incident->assigned_name ?: '未割当');
        if ($n->kind === NotificationKind::REMINDER) {
            $lines[] = '※ まだ確認・復旧が記録されていません。Insights で「確認」を押すと正式な受領になります。';
        }
        if ($runbook) {
            $lines[] = '初動: '.mb_substr(trim((string) preg_replace('/\s+/', ' ', (string) $runbook->first_checks)), 0, 200);
        }
        $lines[] = '詳細: '.self::publicUrl('/monitor/incidents/'.$incident->public_id);
        $lines[] = "(算盤 監視 / 通知番号 N-{$n->id})";

        return $this->sanitize(implode("\n", $lines));
    }

    /** @param array<int,array{incident:Incident,notification:Notification,site:?Site}> $items */
    public function forBatch(array $items, int $remaining): string
    {
        $client = $this->sites->clientName();
        $env = $this->sites->environmentLabel();
        $lines = ["[{$client} {$env}] エラー ".count($items).'件をまとめて通知'.($remaining > 0 ? "（残 {$remaining} 件は次便）" : '')];
        foreach ($items as $it) {
            $i = $it['incident'];
            $lines[] = sprintf('• [%s][%s] %s %s — %s', $it['site'] ? $this->sites->label($it['site']->application_code) : '?', $i->severity->value, $i->public_id, $it['notification']->kind->label(), mb_substr($i->title, 0, 80));
        }
        $lines[] = '詳細: '.self::publicUrl('/monitor/incidents');
        $lines[] = '(算盤 監視 / まとめ通知)';

        return $this->sanitize(implode("\n", $lines));
    }

    public function sanitize(string $text): string
    {
        $t = Redactor::text($text) ?? '';
        // 外部入力由来のメンションを無効化（承認済みメンションは dispatcher が末尾に付加）
        $t = preg_replace('/<!(channel|here|everyone)>/i', '[$1]', $t);
        $t = preg_replace('/(?<![\w])@(channel|here|everyone)\b/i', '[$1]', $t);
        $t = preg_replace('/<@[A-Z0-9]+>/', '[mention]', $t);
        $t = preg_replace('/<!subteam\^[^>]+>/', '[group]', $t);

        return $t;
    }

    private function scopeLine(Incident $i): ?string
    {
        $parts = [];
        if ($i->business_date) {
            $parts[] = '対象営業日: '.$i->business_date->format('Y-m-d');
        }
        foreach ((array) $i->scope_json as $k => $v) {
            if ($k === 'business_date') {
                continue;
            }
            $label = match ($k) {
                'stores', 'store_codes' => '対象店舗',
                'campaigns' => '対象企画',
                'collector' => '収集器',
                'node' => 'ノード',
                default => (string) $k,
            };
            $val = is_array($v) ? (count($v) > 6 ? count($v).'件' : implode(',', $v)) : (string) $v;
            $parts[] = "{$label}: {$val}";
        }

        return $parts ? implode(' / ', $parts) : null;
    }

    private function metricsLine(array $m): string
    {
        $out = [];
        foreach (array_slice($m, 0, 6, true) as $k => $v) {
            $out[] = $k.'='.(is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE));
        }

        return implode(', ', $out);
    }

    private function jst(?\DateTimeInterface $d): string
    {
        return $d ? \Carbon\CarbonImmutable::instance($d)->setTimezone('Asia/Tokyo')->format('m/d H:i') : '-';
    }

    /** 通知に載せる公開 URL。常駐プロセスでは APP_URL しか無いため、MONITOR_PUBLIC_BASE_URL（Twilio 応答用と同じ）を優先する */
    public static function publicUrl(string $path): string
    {
        $base = rtrim((string) (config('monitoring.phone.public_base_url') ?: config('app.url')), '/');

        return $base.'/'.ltrim($path, '/');
    }
}
