<?php

namespace App\Monitoring\Services;

use App\Models\Monitoring\DeliveryBatch;
use App\Models\Monitoring\Incident;
use App\Models\Monitoring\Notification;
use App\Models\Monitoring\NotificationAttempt;
use App\Models\Monitoring\Runbook;
use App\Monitoring\Enums\NotificationKind;
use App\Monitoring\Enums\NotificationStatus;
use App\Monitoring\Support\PublicId;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * 通知配送ワーカー（仕様書 13.4 / 13.5）。
 * - 対象行を短い取引で lease してから、取引外で HTTP 送信する。外部通信中に行ロックを保持しない。
 * - 成功時の UPDATE は lease_token が一致する行だけ。期限切れ lease は回収する。
 * - 429 は Retry-After を尊重、5xx/timeout は指数待機、4xx は BLOCKED。未送信を DELIVERED に変えない。
 */
class NotificationDispatcher
{
    public function __construct(
        private readonly SlackMessageBuilder $builder,
        private readonly SiteRegistry $sites,
        private readonly SelfMonitor $selfMonitor,
        private readonly NotificationQueue $queue,
        private readonly CounterService $counters,
        private readonly \App\Monitoring\Notify\MailNotifier $mail,
        private readonly \App\Monitoring\Notify\PhoneNotifier $phone,
        private readonly NotificationSettings $settings,
        private readonly SecretStore $secrets,
    ) {}

    /** @return array{sent:int,retried:int,blocked:int,cancelled:int,skipped:int} */
    public function dispatch(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $stats = ['sent' => 0, 'retried' => 0, 'blocked' => 0, 'cancelled' => 0, 'skipped' => 0];

        $this->reclaimExpiredLeases($now);

        if ($this->isGloballyDisabled($now)) {
            $stats['skipped'] = -1; // 画面バナーで表示。未送信は保持する

            return $stats;
        }

        $this->mailFallbackSweep($now);

        // 経路ごとに lease する（Slack の流量制限がメール・電話を止めない）
        $limit = (int) config('monitoring.notification.rate_limit_per_minute', 10);
        $hour = $this->sentThisHour($now);
        $slackCap = (int) config('monitoring.notification.max_per_hour', 60);
        $mail = $this->settings->mail();
        $mailCap = (int) ($mail['max_per_hour'] ?? 20);
        $mailInterval = (int) ($mail['min_interval_minutes'] ?? 0);
        $lastMail = Notification::query()->where('route_key', NotificationQueue::ROUTE_MAIL)->whereNotNull('delivered_at')->max('delivered_at');
        $mailOk = ($mailCap <= 0 || $hour['mail'] < $mailCap) && (! $lastMail || $mailInterval <= 0 || CarbonImmutable::parse($lastMail, 'UTC')->addMinutes($mailInterval)->lessThanOrEqualTo($now));
        // 上限に達した経路は lease しない（PENDING のまま残り、次の時間枠でまとめて配送する。DELIVERED にはしない）
        $leased = ($slackCap <= 0 || $hour['slack'] < $slackCap ? $this->lease($now, $limit, 'slack') : collect())
            ->concat($mailOk ? $this->lease($now, (int) config('monitoring.mail.batch_max_incidents', 20), NotificationQueue::ROUTE_MAIL) : collect())
            ->concat($this->lease($now, 20, NotificationQueue::ROUTE_PHONE));
        if ($leased->isEmpty()) {
            return $stats;
        }

        // 配送直前に現在の世代・状態を確認（古い世代の復旧・継続は CANCELLED）
        $incidents = Incident::query()->whereIn('id', $leased->pluck('incident_id')->filter()->unique())->get()->keyBy('id');
        $toSend = [];
        foreach ($leased as $n) {
            $incident = $n->incident_id ? $incidents->get($n->incident_id) : null;
            if ($n->incident_id && ! $incident) {
                $this->finish($n, NotificationStatus::CANCELLED, null, 'incident_missing', $now);
                $stats['cancelled']++;

                continue;
            }
            if ($incident && (int) $incident->generation !== (int) $n->generation && in_array($n->kind, [NotificationKind::RECOVERY, NotificationKind::REMINDER, NotificationKind::SUPPRESSION_EXPIRED], true)) {
                $this->finish($n, NotificationStatus::CANCELLED, null, 'stale_generation', $now);
                $stats['cancelled']++;

                continue;
            }
            if ($incident && $n->kind === NotificationKind::RECOVERY && $incident->observation_state->value === 'ACTIVE') {
                $this->finish($n, NotificationStatus::CANCELLED, null, 'reactivated_before_send', $now);
                $stats['cancelled']++;

                continue;
            }
            $toSend[] = ['notification' => $n, 'incident' => $incident];
        }

        // 同時多発時は ERROR をまとめ配送（最大20件）。CRITICAL は個別に先行。
        $batchMax = (int) config('monitoring.notification.batch_max_incidents', 20);
        $groups = collect($toSend)->groupBy(fn ($x) => $x['notification']->route_key);
        foreach ($groups as $routeKey => $items) {
            $items = $items->values();
            if ($routeKey === NotificationQueue::ROUTE_MAIL) {
                $this->sendMail($items->all(), $now, $stats);

                continue;
            }
            if ($routeKey === NotificationQueue::ROUTE_PHONE) {
                $this->handlePhone($items->all(), $now, $stats);

                continue;
            }
            $singles = $items->filter(fn ($x) => ! $x['incident'] || $x['notification']->severity === 'CRITICAL' || $items->count() <= 3);
            $batched = $items->reject(fn ($x) => $singles->contains($x));

            foreach ($singles as $x) {
                $this->sendSingle($x['notification'], $x['incident'], (string) $routeKey, $now, $stats);
            }
            if ($batched->isNotEmpty()) {
                foreach ($batched->chunk($batchMax) as $chunk) {
                    $this->sendBatch($chunk->values()->all(), (string) $routeKey, $now, $stats, max(0, $batched->count() - $batchMax));
                }
            }
        }

        $this->counters->flush();

        return $stats;
    }

    /** 今の時間枠に送った件数（経路別。まとめ配送は 1 通で数える） @return array{slack:int,mail:int} */
    private function sentThisHour(CarbonImmutable $now): array
    {
        $since = $now->startOfHour();
        $count = fn (string $channel) => (int) \App\Models\Monitoring\NotificationAttempt::query()->where('result', 'DELIVERED')->where('started_at', '>=', $since)
            ->whereIn('notification_id', Notification::query()->select('id')->where('channel', $channel)->where('delivered_at', '>=', $since))
            ->selectRaw('COUNT(DISTINCT COALESCE(delivery_batch_id, -notification_id)) AS c')->value('c');

        return ['slack' => $count('slack'), 'mail' => $count('mail')];
    }

    /** Slack が配送不可 or 5 分以上未送信 → 代替メール（v2 第7.1） */
    private function mailFallbackSweep(CarbonImmutable $now): void
    {
        $min = (int) config('monitoring.mail.slack_fallback_minutes', 5);
        $stale = Notification::query()->whereNotIn('route_key', [NotificationQueue::ROUTE_MAIL, NotificationQueue::ROUTE_PHONE])
            ->whereIn('status', [NotificationStatus::PENDING->value, NotificationStatus::RETRY_WAIT->value])
            ->whereNotNull('incident_id')->where('created_at', '<=', $now->subMinutes($min))->orderBy('id')->limit(20)->get();
        foreach ($stale as $n) {
            $this->queue->ensureMailFallback($n);
        }
    }

    /** @param array<int,array{notification:Notification,incident:?Incident}> $items */
    private function sendMail(array $items, CarbonImmutable $now, array &$stats): void
    {
        $notifications = array_map(fn ($x) => $x['notification'], $items);
        if (! $this->mail->configured()) {
            foreach ($notifications as $n) { // 未設定は BLOCKED にしない（画面バナーで表示）
                $this->finish($n, NotificationStatus::CANCELLED, null, 'not_configured', $now);
                $stats['cancelled']++;
            }

            return;
        }
        if (count($items) === 1) {
            $x = $items[0];
            $site = $x['incident'] ? $this->sites->siteById((int) $x['incident']->site_id) : null;
            $runbook = $x['incident']?->runbook_key ? Runbook::query()->where('runbook_key', $x['incident']->runbook_key)->where('enabled', true)->orderByDesc('version')->first() : null;
            $text = $this->builder->forNotification($x['notification'], $x['incident'], $site, $runbook);
            $lines = explode("\n", $text);
            $subject = $lines[0].($x['incident'] ? ': '.mb_substr((string) $x['incident']->title, 0, 80) : '');
            $batch = null;
        } else {
            $batch = DeliveryBatch::query()->create(['public_id' => 'TMP-'.Str::random(8), 'route_key' => NotificationQueue::ROUTE_MAIL,
                'notification_ids_json' => array_map(fn ($n) => (int) $n->id, $notifications), 'created_at' => $now, 'status' => 'PENDING']);
            $batch->public_id = PublicId::batch((int) $batch->id);
            $batch->save();
            $text = $this->builder->forBatch(array_map(fn ($x) => ['incident' => $x['incident'], 'notification' => $x['notification'],
                'site' => $x['incident'] ? $this->sites->siteById((int) $x['incident']->site_id) : null], array_values(array_filter($items, fn ($x) => $x['incident']))), 0);
            $subject = '['.$this->sites->clientName().' '.$this->sites->environmentLabel().']['.$this->sites->label('INSIGHTS').'] エラー '.count($items).' 件（まとめ通知 '.$batch->public_id.'）';
        }
        $r = $this->mail->send($subject, $text);
        $result = ['ok' => $r['ok'], 'status' => $r['ok'] ? 250 : ($r['permanent'] ? 550 : null), 'retry_after' => null, 'error' => $r['error']];
        if ($r['ok']) {
            $this->selfMonitor->recovered('MONITOR_NOTIFICATION_BLOCKED', ['route' => NotificationQueue::ROUTE_MAIL], 'メール通知が配送できた');
        }
        foreach ($notifications as $n) {
            $this->recordAttempt($n, $batch, $result, $now);
            if ($r['ok']) {
                $this->finish($n, NotificationStatus::DELIVERED, null, null, $now, $batch);
                $stats['sent']++;
            } elseif ($r['permanent']) {
                $this->finish($n, NotificationStatus::BLOCKED, null, 'mail_rejected', $now, $batch);
                $stats['blocked']++;
            } else {
                $this->retry($n, $this->nextRetryAt($n, $now), null, 'mail_transient', $now);
                $stats['retried']++;
            }
        }
        if ($batch) {
            $batch->update(['status' => $r['ok'] ? 'DELIVERED' : 'FAILED', 'delivered_at' => $r['ok'] ? $now : null, 'expires_at' => $now->addDays((int) config('monitoring.retention.notification_days', 90))]);
        }
        if (! $r['ok'] && $r['permanent']) {
            $this->selfMonitor->notificationBlocked(NotificationQueue::ROUTE_MAIL, 'smtp_rejected');
        }
    }

    /** 電話。発信前の新規が batch_threshold 件以上なら 1 本の発信にまとめる（V-157）。 @param array<int,array{notification:Notification,incident:?Incident}> $items */
    private function handlePhone(array $items, CarbonImmutable $now, array &$stats): void
    {
        // 配置あたり同時 1 列: 進行中の発信列があれば新規は全てそこへまとめ、無ければ 1 件だけ新しい列を始める（同じ担当者へ同時に複数回かけない）
        $fresh = array_values(array_filter($items, fn ($x) => ! $x['notification']->delivery_batch_id && ! \App\Models\Monitoring\CallAttempt::query()->where('notification_id', $x['notification']->id)->exists()));
        // 発信前に既に確認・対応中・解決になった案件は列に入れない（停止中・猶予中に対応された案件へ電話しない）
        foreach ($fresh as $k => $x) {
            if ($x['incident'] && $x['incident']->workflow_status !== \App\Monitoring\Enums\WorkflowStatus::OPEN) {
                $this->finish($x['notification'], NotificationStatus::CANCELLED, null, 'acknowledged_elsewhere', $now);
                $stats['cancelled']++;
                unset($fresh[$k]);
                $items = array_values(array_filter($items, fn ($y) => (int) $y['notification']->id !== (int) $x['notification']->id));
            }
        }
        $fresh = array_values($fresh);
        if ($fresh) {
            $inProgress = $this->phone->activeColumn(array_map(fn ($x) => (int) $x['notification']->id, $fresh));
            $lead = $inProgress ?? $fresh[0]['notification'];
            $others = array_values(array_filter($fresh, fn ($x) => (int) $x['notification']->id !== (int) $lead->id));
            if ($others) {
                $batch = $lead->delivery_batch_id ? DeliveryBatch::query()->find($lead->delivery_batch_id) : null;
                if (! $batch) {
                    $batch = DeliveryBatch::query()->create(['public_id' => 'TMP-'.Str::random(8), 'route_key' => NotificationQueue::ROUTE_PHONE,
                        'notification_ids_json' => [(int) $lead->id], 'created_at' => $now, 'status' => 'PENDING']);
                    $batch->public_id = PublicId::batch((int) $batch->id);
                    $batch->save();
                    Notification::query()->where('id', $lead->id)->update(['delivery_batch_id' => $batch->id]);
                    $lead->delivery_batch_id = $batch->id;
                }
                $batch->notification_ids_json = array_values(array_unique(array_merge((array) $batch->notification_ids_json, array_map(fn ($x) => (int) $x['notification']->id, $others))));
                $batch->save();
                foreach ($others as $x) { // まとめた側は発信しない。DELIVERED にはしない
                    $this->finish($x['notification'], NotificationStatus::CANCELLED, null, 'merged_into_call:'.$lead->id, $now, $batch);
                    $stats['cancelled']++;
                }
                $merged = array_map(fn ($x) => (int) $x['notification']->id, $others);
                $items = array_values(array_filter($items, fn ($x) => ! in_array((int) $x['notification']->id, $merged, true)));
            }
        }
        foreach ($items as $x) {
            $n = $x['notification'];
            $d = $this->phone->decide($n, $now);
            switch ($d['action']) {
                case 'deliver':
                    $this->finish($n, NotificationStatus::DELIVERED, null, null, $now);
                    $this->closeCallBatch($n, 'DELIVERED', $now);
                    $stats['sent']++;
                    break;
                case 'cancel':
                    $this->finish($n, NotificationStatus::CANCELLED, null, $d['reason'] ?? 'cancelled', $now);
                    $this->closeCallBatch($n, 'CANCELLED', $now);
                    $stats['cancelled']++;
                    break;
                case 'unanswered':
                    $this->finish($n, NotificationStatus::UNANSWERED, null, 'no_acknowledgement', $now);
                    $this->closeCallBatch($n, 'UNANSWERED', $now);
                    $stats['cancelled']++;
                    break;
                case 'block':
                    $this->finish($n, NotificationStatus::BLOCKED, $d['http'] ?? null, $d['reason'] ?? 'blocked', $now);
                    $this->closeCallBatch($n, 'FAILED', $now);
                    $this->selfMonitor->notificationBlocked(NotificationQueue::ROUTE_PHONE, (string) ($d['reason'] ?? 'blocked'));
                    $stats['blocked']++;
                    break;
                case 'retry':
                    $this->retry($n, $d['at'] ?? $now->addSeconds(30), $d['http'] ?? null, (string) ($d['reason'] ?? 'transient'), $now);
                    $stats['retried']++;
                    break;
                default: // wait: 発信列の途中。誤って再試行扱いにしない
                    Notification::query()->where('id', $n->id)->where('lease_token', $n->lease_token)->update([
                        'status' => NotificationStatus::RETRY_WAIT->value, 'available_at' => $d['at'] ?? $now->addSeconds(30),
                        'lease_token' => null, 'lease_expires_at' => null, 'attempt_count' => DB::raw('CASE WHEN attempt_count > 0 THEN attempt_count - 1 ELSE 0 END'),
                    ]);
            }
        }
    }

    /** 発信列の終了時にまとめ（batch）の状態と保持期限を確定する */
    private function closeCallBatch(Notification $n, string $status, CarbonImmutable $now): void
    {
        if ($n->delivery_batch_id) {
            DeliveryBatch::query()->where('id', $n->delivery_batch_id)->update(['status' => $status, 'delivered_at' => $status === 'DELIVERED' ? $now : null, 'expires_at' => $now->addDays((int) config('monitoring.retention.notification_days', 90))]);
        }
    }

    private function sendSingle(Notification $n, ?Incident $incident, string $routeKey, CarbonImmutable $now, array &$stats): void
    {
        $site = $incident ? $this->sites->siteById((int) $incident->site_id) : null;
        $runbook = $incident?->runbook_key ? Runbook::query()->where('runbook_key', $incident->runbook_key)->where('enabled', true)->orderByDesc('version')->first() : null;
        $text = $this->builder->forNotification($n, $incident, $site, $runbook);
        $this->deliver([$n], $text, $routeKey, $now, $stats, null);
    }

    /** @param array<int,array{notification:Notification,incident:?Incident}> $items */
    private function sendBatch(array $items, string $routeKey, CarbonImmutable $now, array &$stats, int $remaining): void
    {
        $batch = DeliveryBatch::query()->create([
            'public_id' => 'TMP-'.Str::random(8),
            'route_key' => $routeKey,
            'notification_ids_json' => array_map(fn ($x) => (int) $x['notification']->id, $items),
            'created_at' => $now,
            'status' => 'PENDING',
        ]);
        $batch->public_id = PublicId::batch((int) $batch->id);
        $batch->save();
        $text = $this->builder->forBatch(array_map(fn ($x) => [
            'incident' => $x['incident'], 'notification' => $x['notification'],
            'site' => $this->sites->siteById((int) $x['incident']->site_id),
        ], $items), $remaining);
        $this->deliver(array_map(fn ($x) => $x['notification'], $items), $text, $routeKey, $now, $stats, $batch);
    }

    /** @param Notification[] $notifications */
    private function deliver(array $notifications, string $text, string $routeKey, CarbonImmutable $now, array &$stats, ?DeliveryBatch $batch): void
    {
        $route = config('monitoring.notification.routes.'.$routeKey);
        // 既定経路は画面の設定（暗号化保存）> .env。それ以外の経路は .env のみ
        $webhook = $route && $routeKey === config('monitoring.notification.default_route', 'error_channel')
            ? $this->secrets->get('slack.webhook_url')
            : ($route && ! empty($route['webhook_env']) ? \App\Monitoring\Support\Env::get($route['webhook_env']) : null);
        if (! $route || ! $webhook) {
            foreach ($notifications as $n) {
                $this->finish($n, NotificationStatus::BLOCKED, null, 'route_not_configured', $now, $batch);
                $stats['blocked']++;
            }
            $this->selfMonitor->notificationBlocked($routeKey, 'route_not_configured');

            return;
        }
        if (! empty($route['mention'])) {
            $text .= "\n".$route['mention'];
        }
        $result = $this->post($webhook, $text);

        foreach ($notifications as $n) {
            $this->recordAttempt($n, $batch, $result, $now);
            if ($result['ok']) {
                $this->finish($n, NotificationStatus::DELIVERED, $result['status'], null, $now, $batch);
                $stats['sent']++;
            } elseif ($result['status'] === 429) {
                $delay = max(1, (int) ($result['retry_after'] ?? 60));
                $this->retry($n, $now->addSeconds($delay), $result['status'], 'rate_limited', $now);
                $stats['retried']++;
            } elseif ($result['status'] !== null && $result['status'] >= 400 && $result['status'] < 500) {
                $this->finish($n, NotificationStatus::BLOCKED, $result['status'], 'permanent_'.$result['status'], $now, $batch);
                $stats['blocked']++;
            } else {
                $this->retry($n, $this->nextRetryAt($n, $now), $result['status'], $result['error'] ?? 'transient', $now);
                $stats['retried']++;
            }
        }
        if ($result['ok']) {
            $this->selfMonitor->recovered('MONITOR_NOTIFICATION_BLOCKED', ['route' => $routeKey], 'Slack 通知経路 '.$routeKey.' が配送できた');
        }
        if ($batch) {
            $batch->status = $result['ok'] ? 'DELIVERED' : 'FAILED';
            $batch->delivered_at = $result['ok'] ? $now : null;
            $batch->expires_at = $now->addDays((int) config('monitoring.retention.notification_days', 90));
            $batch->save();
        }
        if (! $result['ok'] && $result['status'] !== null && $result['status'] >= 400 && $result['status'] < 500 && $result['status'] !== 429) {
            $this->selfMonitor->notificationBlocked($routeKey, 'http_'.$result['status']);
        }
    }

    /** @return array{ok:bool,status:int|null,retry_after:int|null,error:string|null} */
    private function post(string $webhook, string $text): array
    {
        try {
            $resp = Http::timeout((int) config('monitoring.notification.http_timeout_seconds', 10))
                ->connectTimeout(5)
                ->post($webhook, ['text' => $text]);
        } catch (ConnectionException $e) {
            return ['ok' => false, 'status' => null, 'retry_after' => null, 'error' => 'connection'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => null, 'retry_after' => null, 'error' => 'exception'];
        }
        $status = $resp->status();

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'retry_after' => $resp->header('Retry-After') !== '' ? (int) $resp->header('Retry-After') : null,
            'error' => $status >= 200 && $status < 300 ? null : 'http_'.$status,
        ];
    }

    private function nextRetryAt(Notification $n, CarbonImmutable $now): CarbonImmutable
    {
        $delays = (array) config('monitoring.notification.retry_delays_seconds', [30, 120, 600, 1800, 3600]);
        $i = min(max(0, (int) $n->attempt_count - 1), count($delays) - 1);
        $delay = (int) $delays[$i] + random_int(0, (int) config('monitoring.notification.retry_jitter_seconds', 15));

        return $now->addSeconds($delay);
    }

    /** @return \Illuminate\Support\Collection<int,Notification> */
    private function lease(CarbonImmutable $now, int $limit, string $channel = 'slack'): \Illuminate\Support\Collection
    {
        $token = Str::random(32);
        $leaseSec = (int) config('monitoring.notification.lease_seconds', 120);
        $ids = DB::connection('monitor_store')->transaction(function () use ($now, $limit, $token, $leaseSec, $channel) {
            $q = Notification::query()
                ->when($channel === 'slack', fn ($q) => $q->whereNotIn('route_key', [NotificationQueue::ROUTE_MAIL, NotificationQueue::ROUTE_PHONE]))
                ->when($channel !== 'slack', fn ($q) => $q->where('route_key', $channel))
                ->whereIn('status', [NotificationStatus::PENDING->value, NotificationStatus::RETRY_WAIT->value])
                ->where('available_at', '<=', $now)
                ->orderByRaw("CASE WHEN severity = 'CRITICAL' THEN 0 ELSE 1 END")
                ->orderBy('id')
                ->limit($limit);
            if (DB::connection('monitor_store')->getDriverName() === 'mysql') {
                $q->lock('FOR UPDATE SKIP LOCKED'); // 監視テーブル限定
            }
            $ids = $q->pluck('id')->all();
            if ($ids) {
                Notification::query()->whereIn('id', $ids)->update([
                    'status' => NotificationStatus::SENDING->value,
                    'lease_token' => $token,
                    'lease_expires_at' => $now->addSeconds($leaseSec),
                    'attempt_count' => DB::raw('attempt_count + 1'),
                ]);
            }

            return $ids;
        }, 3);

        return Notification::query()->whereIn('id', $ids)->where('lease_token', $token)->get();
    }

    private function reclaimExpiredLeases(CarbonImmutable $now): int
    {
        return Notification::query()
            ->where('status', NotificationStatus::SENDING->value)
            ->where('lease_expires_at', '<', $now)
            ->update(['status' => NotificationStatus::RETRY_WAIT->value, 'lease_token' => null, 'lease_expires_at' => null, 'available_at' => $now, 'last_error_code' => 'lease_expired']);
    }

    private function finish(Notification $n, NotificationStatus $status, ?int $http, ?string $error, CarbonImmutable $now, ?DeliveryBatch $batch = null): void
    {
        $updated = Notification::query()->where('id', $n->id)->where('lease_token', $n->lease_token)->update([
            'status' => $status->value,
            'delivered_at' => $status === NotificationStatus::DELIVERED ? $now : null,
            'last_http_status' => $http,
            'last_error_code' => $error,
            'cancel_reason' => $status === NotificationStatus::CANCELLED ? $error : null,
            'delivery_batch_id' => $batch?->id ?? $n->delivery_batch_id,
            'lease_token' => null,
            'lease_expires_at' => null,
            'expires_at' => in_array($status, [NotificationStatus::DELIVERED, NotificationStatus::CANCELLED, NotificationStatus::UNANSWERED, NotificationStatus::BLOCKED], true)
                ? $now->addDays((int) config('monitoring.retention.notification_days', 90)) : null,
        ]);
        if ($updated) {
            $channel = in_array($n->route_key, [NotificationQueue::ROUTE_MAIL, NotificationQueue::ROUTE_PHONE], true) ? $n->route_key : 'slack';
            $siteId = $n->incident_id ? (int) Incident::query()->whereKey($n->incident_id)->value('site_id') : (int) ($this->sites->site('INSIGHTS')?->id ?? 0);
            if ($siteId) {
                $st = strtolower($status->value);
                $this->counters->add($siteId, CounterService::KEY_NOTIFICATION_HOURLY, $now, [$st => 1, $channel.'_'.$st => 1]);
            }
            if ($status === NotificationStatus::BLOCKED && $channel === 'slack') {
                $this->queue->ensureMailFallback($n);
            }
        }
        $isSlack = ! in_array($n->route_key, [NotificationQueue::ROUTE_MAIL, NotificationQueue::ROUTE_PHONE], true);
        if ($updated && $n->incident_id && $status === NotificationStatus::DELIVERED && $isSlack) {
            Incident::query()->where('id', $n->incident_id)->update(['last_delivery_status' => 'DELIVERED', 'last_delivered_at' => $now]);
        } elseif ($updated && $n->incident_id && $status === NotificationStatus::BLOCKED && $isSlack) {
            Incident::query()->where('id', $n->incident_id)->update(['last_delivery_status' => 'BLOCKED']);
        }
    }

    private function retry(Notification $n, CarbonImmutable $at, ?int $http, string $error, CarbonImmutable $now): void
    {
        Notification::query()->where('id', $n->id)->where('lease_token', $n->lease_token)->update([
            'status' => NotificationStatus::RETRY_WAIT->value,
            'available_at' => $at,
            'last_http_status' => $http,
            'last_error_code' => $error,
            'lease_token' => null,
            'lease_expires_at' => null,
        ]);
        if ($n->incident_id && ! in_array($n->route_key, [NotificationQueue::ROUTE_MAIL, NotificationQueue::ROUTE_PHONE], true)) {
            Incident::query()->where('id', $n->incident_id)->update(['last_delivery_status' => 'RETRY_WAIT']);
        }
    }

    private function recordAttempt(Notification $n, ?DeliveryBatch $batch, array $result, CarbonImmutable $now): void
    {
        NotificationAttempt::query()->create([
            'notification_id' => $n->id,
            'delivery_batch_id' => $batch?->id,
            'attempt_no' => (int) $n->attempt_count,
            'started_at' => $now,
            'finished_at' => CarbonImmutable::now('UTC'),
            'result' => $result['ok'] ? 'DELIVERED' : (($result['status'] ?? 0) === 429 || ($result['status'] === null) || $result['status'] >= 500 ? 'RETRY' : 'BLOCKED'),
            'http_status' => $result['status'],
            'retry_after_seconds' => $result['retry_after'],
            'sanitized_error' => $result['error'] ? mb_substr((string) $result['error'], 0, 255) : null,
            'expires_at' => $now->addDays((int) config('monitoring.retention.notification_days', 90)),
        ]);
    }

    public function isGloballyDisabled(CarbonImmutable $now): bool
    {
        if ($this->settings->pause($now)) {
            return true; // 画面の「アラート一時停止」
        }
        if (! config('monitoring.notification.global_disable')) {
            return false;
        }
        $until = config('monitoring.notification.global_disable_until');
        if ($until && CarbonImmutable::parse($until)->lessThan($now)) {
            return false; // 期限切れは再開
        }

        return true;
    }
}
