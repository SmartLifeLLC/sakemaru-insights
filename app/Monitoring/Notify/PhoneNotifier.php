<?php

namespace App\Monitoring\Notify;

use App\Models\Monitoring\CallAttempt;
use App\Models\Monitoring\Contact;
use App\Models\Monitoring\DeliveryBatch;
use App\Models\Monitoring\Incident;
use App\Models\Monitoring\Notification;
use App\Monitoring\Enums\WorkflowStatus;
use App\Monitoring\Services\AuditService;
use App\Monitoring\Services\IncidentService;
use App\Monitoring\Services\NotificationSettings;
use App\Monitoring\Services\SecretStore;
use App\Monitoring\Services\SiteRegistry;
use App\Monitoring\Support\Redactor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * 電話通知（Twilio、v2 第7.2 / V-150〜160）。1 件の PHONE 通知 = 1 本の「発信列」。
 * 配送ワーカーが通知を lease するたびに decide() を呼び、次の操作（発信・待機・完了）を決める。状態は call_attempts に残す。
 *
 *  - 名簿の順に発信。応答なし・不在・失敗・1 以外 → next_contact_seconds 後に次の担当者
 *  - 名簿 1 巡で確認なし → round_interval_seconds 後に次の巡回（max_rounds まで）。超えたら UNANSWERED（配送不可ではない）
 *  - 1 が押されたら案件を ACKNOWLEDGED（actor = 担当者名、監査「電話で確認」）にして発信列を DELIVERED で終える
 *  - 画面・Slack で先に確認・解決された場合は残りの発信を取り消す
 */
class PhoneNotifier
{
    private const ACTIVE = ['scheduled', 'queued', 'initiated', 'ringing', 'in-progress', 'answered'];

    public function __construct(
        private readonly TwilioClient $twilio,
        private readonly SecretStore $secrets,
        private readonly NotificationSettings $settings,
        private readonly SiteRegistry $sites,
        private readonly IncidentService $incidents,
        private readonly AuditService $audit,
    ) {}

    /**
     * @return array{action:string,at?:CarbonImmutable,reason?:?string,http?:?int}
     *                                                                             action: deliver / cancel / block / unanswered / wait / retry
     */
    public function decide(Notification $n, CarbonImmutable $now): array
    {
        $p = $this->settings->phone();
        $incidents = $this->incidentsOf($n);
        if (! $incidents) {
            return ['action' => 'cancel', 'reason' => 'incident_missing'];
        }
        $attempts = CallAttempt::query()->where('notification_id', $n->id)->orderBy('id')->get();
        $last = $attempts->last();
        if ($attempts->contains(fn ($a) => $a->status === 'acknowledged')) {
            return ['action' => 'deliver', 'reason' => null];
        }
        if (collect($incidents)->every(fn (Incident $i) => $i->workflow_status !== WorkflowStatus::OPEN)) {
            $this->cancelActive($attempts, $now);

            return ['action' => 'cancel', 'reason' => 'acknowledged_elsewhere'];
        }
        if ($attempts->isEmpty() && collect($incidents)->every(fn (Incident $i) => $i->observation_state === \App\Monitoring\Enums\ObservationState::RECOVERED)) {
            return ['action' => 'cancel', 'reason' => 'recovered_before_call']; // 猶予の間に復旧した
        }
        if (! $this->settings->phoneAllowedHere()) {
            return ['action' => 'cancel', 'reason' => 'env_not_allowed'];
        }
        if (! $this->secrets->twilioConfigured()) {
            return ['action' => 'cancel', 'reason' => 'not_configured'];
        }

        // 発信中: コールバック待ち（来なければ 5 分で打ち切り）
        if ($last && in_array($last->status, self::ACTIVE, true)) {
            $since = CarbonImmutable::instance($last->started_at ?? $last->scheduled_at);
            if ($since->addSeconds((int) $p['ring_timeout_seconds'] + 270)->greaterThan($now)) {
                return ['action' => 'wait', 'at' => $now->addSeconds(30)];
            }
            $last->status = 'no-callback';
            $last->finished_at = $now;
            $last->save();
        }
        if ($attempts->where('status', 'error')->count() >= 5) {
            return ['action' => 'block', 'reason' => 'twilio_unavailable'];
        }

        if ($attempts->isEmpty()) {
            // 新しい発信列を始める前の多発防止: クールダウン・1 晩の列数上限
            $cool = (int) ($p['cooldown_minutes'] ?? 0);
            $lastEnd = $this->lastColumnEndedAt();
            if ($cool > 0 && $lastEnd && $lastEnd->addMinutes($cool)->greaterThan($now)) {
                return ['action' => 'wait', 'at' => $lastEnd->addMinutes($cool)];
            }
            $maxCols = (int) ($p['max_columns_per_window'] ?? 0);
            if ($maxCols > 0 && $this->columnsStartedSince($this->settings->phoneWindowStart($now)) >= $maxCols) {
                return ['action' => 'cancel', 'reason' => 'phone_capped'];
            }
        }
        $contacts = $this->contacts($now);
        $perContact = (int) ($p['max_calls_per_contact_per_window'] ?? 0);
        if ($perContact > 0 && $contacts) {
            $since = $this->settings->phoneWindowStart($now);
            $counts = CallAttempt::query()->where('scheduled_at', '>=', $since)->whereNotIn('status', ['error', 'cancelled'])->selectRaw('contact_id, COUNT(*) c')->groupBy('contact_id')->pluck('c', 'contact_id');
            $contacts = array_values(array_filter($contacts, fn (Contact $c) => (int) ($counts[$c->id] ?? 0) < $perContact));
            if ($contacts === []) {
                return ['action' => 'unanswered', 'reason' => 'contacts_capped']; // 全員が今夜の着信上限。これ以上かけない
            }
        }
        if ($contacts === []) {
            if (! Contact::query()->where('enabled', true)->exists()) {
                return ['action' => 'cancel', 'reason' => 'no_contacts'];
            }

            return ['action' => 'wait', 'at' => $now->addMinutes(5)]; // 全員が在席時間外。在席になるまで待つ（取り消さない）
        }
        // 次の発信先と巡回
        $round = 1;
        $index = 0;
        $readyAt = $now;
        if ($last) {
            $round = (int) $last->round_no;
            $finished = CarbonImmutable::instance($last->finished_at ?? $now);
            if ($last->status === 'error') { // 一時エラーは同じ相手に再試行
                $pos = array_search((int) $last->contact_id, array_map(fn ($c) => (int) $c->id, $contacts), true);
                $index = $pos === false ? 0 : $pos;
                $readyAt = $finished->addSeconds(min(600, 30 * 2 ** max(0, $attempts->where('status', 'error')->count() - 1)));
            } else {
                $pos = array_search((int) $last->contact_id, array_map(fn ($c) => (int) $c->id, $contacts), true);
                $index = $pos === false ? 0 : $pos + 1;
                $readyAt = $finished->addSeconds((int) $p['next_contact_seconds']);
                if ($index >= count($contacts)) {
                    $round++;
                    $index = 0;
                    $readyAt = $finished->addSeconds((int) $p['round_interval_seconds']);
                }
            }
        }
        if ($round > (int) $p['max_rounds']) {
            return ['action' => 'unanswered', 'reason' => 'no_acknowledgement'];
        }
        if ($readyAt->greaterThan($now)) {
            return ['action' => 'wait', 'at' => $readyAt];
        }

        return $this->call($n, $contacts[$index], $round, $attempts->count() + 1, $incidents, $now);
    }

    /** @param Incident[] $incidents */
    private function call(Notification $n, Contact $contact, int $round, int $attemptNo, array $incidents, CarbonImmutable $now): array
    {
        $p = $this->settings->phone();
        $token = Str::random(40);
        $attempt = CallAttempt::query()->create([
            'notification_id' => $n->id, 'contact_id' => $contact->id, 'round_no' => $round, 'attempt_no' => $attemptNo,
            'token_hash' => hash('sha256', $token), 'token_expires_at' => $now->addSeconds((int) $p['token_ttl_seconds']),
            'scheduled_at' => $now, 'started_at' => $now, 'status' => 'scheduled',
            'expires_at' => $now->addDays((int) config('monitoring.retention.notification_days', 90)),
        ]);
        $base = $this->publicBase();
        $r = $this->twilio->createCall($contact->phone_e164, $base.'/internal/monitor/voice/'.$token, $base.'/internal/monitor/voice/'.$token.'/status', (int) $p['ring_timeout_seconds']);
        if ($r['ok']) {
            $attempt->update(['status' => 'queued', 'call_sid' => mb_substr((string) $r['sid'], 0, 64)]);

            return ['action' => 'wait', 'at' => $now->addSeconds(60)];
        }
        $attempt->update(['status' => 'error', 'finished_at' => $now, 'sanitized_error' => mb_substr((string) $r['error'], 0, 255)]);
        if ($r['status'] !== null && $r['status'] >= 400 && $r['status'] < 500 && $r['status'] !== 429) {
            return ['action' => 'block', 'reason' => (string) $r['error'], 'http' => $r['status']];
        }

        return ['action' => 'retry', 'reason' => (string) $r['error'], 'http' => $r['status'], 'at' => $now->addSeconds(30)];
    }

    /**
     * テスト発信（delivery_admin のみ・監査は呼び出し側）。案件に紐づかない通知行を作り、その発信として記録する。
     *
     * @return array{ok:bool,message:string}
     */
    public function testCall(Contact $contact, CarbonImmutable $now): array
    {
        if (! $this->settings->phoneAllowedHere()) {
            return ['ok' => false, 'message' => 'この環境では電話発信が許可されていません（MONITOR_PHONE_ALLOW_ENV）。'];
        }
        if (! $this->secrets->twilioConfigured()) {
            return ['ok' => false, 'message' => 'Twilio の設定が未登録です。'];
        }
        $n = Notification::query()->create(['incident_id' => null, 'generation' => 1, 'notification_key' => 'sys:phone-test:'.Str::uuid(), 'kind' => 'TEST',
            'route_key' => 'phone', 'channel' => 'phone', 'severity' => 'INFO', 'payload_snapshot_json' => ['title' => 'テスト発信', 'contact' => $contact->name],
            'status' => 'DELIVERED', 'available_at' => $now, 'created_at' => $now, 'expires_at' => $now->addDays(90)]);
        $r = $this->call($n, $contact, 1, 1, [], $now);
        $ok = ($r['action'] ?? '') === 'wait';
        if (! $ok) {
            $n->update(['status' => 'BLOCKED', 'last_error_code' => mb_substr((string) ($r['reason'] ?? 'error'), 0, 64)]);
        }

        return ['ok' => $ok, 'message' => $ok ? $contact->name.'（'.$contact->maskedPhone().'）へ発信しました。' : 'テスト発信に失敗しました（'.($r['reason'] ?? 'error').'）。'];
    }

    /**
     * 進行中の発信列（発信を始めていて、まだ確認・終了していない PHONE 通知）。配置あたり 1 列にまとめるために使う。
     *
     * @param  int[]  $exclude  今回新しく来た通知（列の候補にしない）
     */
    public function activeColumn(array $exclude = []): ?Notification
    {
        $ids = Notification::query()->where('route_key', 'phone')->whereIn('status', ['PENDING', 'RETRY_WAIT', 'SENDING'])
            ->whereNotIn('id', $exclude ?: [0])->orderBy('id')->limit(50)->pluck('id')->all();
        foreach ($ids as $id) {
            if (CallAttempt::query()->where('notification_id', $id)->exists() && ! CallAttempt::query()->where('notification_id', $id)->where('status', 'acknowledged')->exists()) {
                return Notification::query()->find($id);
            }
        }

        return null;
    }

    /** 直近の発信列が終わった時刻（確認・未応答・取消のいずれか） */
    public function lastColumnEndedAt(): ?CarbonImmutable
    {
        $t = CallAttempt::query()->whereNotNull('finished_at')->max('finished_at');

        return $t ? CarbonImmutable::parse($t, 'UTC') : null;
    }

    /** 指定時刻以降に始まった発信列の数（= 1 回目の発信の数） */
    public function columnsStartedSince(CarbonImmutable $since): int
    {
        return CallAttempt::query()->where('attempt_no', 1)->where('scheduled_at', '>=', $since)->count();
    }

    /** 今夜の状況（画面表示用） @return array{columns:int,max_columns:int,cooldown_until:?CarbonImmutable,calls_by_contact:array<int,int>} */
    public function windowStatus(CarbonImmutable $now): array
    {
        $p = $this->settings->phone();
        $since = $this->settings->phoneWindowStart($now);
        $lastEnd = $this->lastColumnEndedAt();
        $cool = (int) ($p['cooldown_minutes'] ?? 0);

        return [
            'window_start' => $since,
            'columns' => $this->columnsStartedSince($since),
            'max_columns' => (int) ($p['max_columns_per_window'] ?? 0),
            'cooldown_until' => $cool > 0 && $lastEnd && $lastEnd->addMinutes($cool)->greaterThan($now) ? $lastEnd->addMinutes($cool) : null,
            'calls_by_contact' => CallAttempt::query()->where('scheduled_at', '>=', $since)->whereNotIn('status', ['error', 'cancelled'])->selectRaw('contact_id, COUNT(*) c')->groupBy('contact_id')->pluck('c', 'contact_id')->map(fn ($v) => (int) $v)->all(),
        ];
    }

    /** 名簿（有効・在席時間内、順序どおり） @return Contact[] */
    public function contacts(CarbonImmutable $now): array
    {
        $t = $now->setTimezone('Asia/Tokyo')->format('H:i');

        return Contact::query()->where('enabled', true)->orderBy('order_no')->orderBy('id')->get()
            ->filter(function (Contact $c) use ($t) {
                if (! $c->active_from || ! $c->active_to) {
                    return true;
                }

                return $c->active_from <= $c->active_to ? ($t >= $c->active_from && $t < $c->active_to) : ($t >= $c->active_from || $t < $c->active_to);
            })->values()->all();
    }

    /** まとめ発信なら batch の全通知の案件、通常は 1 件 @return Incident[] */
    public function incidentsOf(Notification $n): array
    {
        $ids = [$n->incident_id];
        if ($n->delivery_batch_id && ($b = DeliveryBatch::query()->find($n->delivery_batch_id))) {
            $ids = Notification::query()->whereIn('id', (array) $b->notification_ids_json)->pluck('incident_id')->all();
        }

        return Incident::query()->whereIn('id', array_filter($ids))->orderBy('id')->get()->all();
    }

    private function cancelActive($attempts, CarbonImmutable $now): void
    {
        foreach ($attempts as $a) {
            if (in_array($a->status, ['scheduled'], true)) {
                $a->update(['status' => 'cancelled', 'finished_at' => $now]);
            }
        }
    }

    public function publicBase(): string
    {
        return rtrim((string) (config('monitoring.phone.public_base_url') ?: config('app.url')), '/');
    }

    /**
     * 読み上げ文（V-155）。顧客名・環境・サービス・エラー番号・要約（80 文字以内、伏字済み）だけ。URL・SQL・電話番号・メールを含めない。
     *
     * @param  Incident[]  $incidents
     */
    public function sayText(array $incidents): string
    {
        $client = $this->sites->clientName();
        $env = $this->sites->environmentLabel();
        $first = $incidents[0];
        $service = $this->sites->siteById((int) $first->site_id)?->display_name ?? '';
        $max = (int) config('monitoring.phone.say_max_chars', 80);
        $summary = self::speakable((string) $first->title, $max);
        $sev = $first->severity->value === 'CRITICAL' ? '重大なエラー' : 'エラー';
        if (count($incidents) > 1) {
            return "酒丸監視です。{$client} {$env} で{$sev}が ".count($incidents)." 件発生しています。最初の案件は {$service}、".self::spellId($first->public_id)."、内容、{$summary}。確認する場合は 1 を押してください。すべての案件を確認済みにします。";
        }

        return "酒丸監視です。{$client} {$env} {$service} で{$sev} ".self::spellId($first->public_id)." が発生しました。内容、{$summary}。確認する場合は 1 を押してください。";
    }

    public static function speakable(string $text, int $max = 80): string
    {
        $t = (string) Redactor::text($text);
        $t = preg_replace('#https?://\S+#u', '', $t);
        $t = preg_replace('/[\w.+-]+@[\w-]+\.[\w.]+/u', '', $t);
        $t = preg_replace('/\b(SELECT|INSERT|UPDATE|DELETE|FROM|WHERE|JOIN|SQLSTATE)\b.*$/iu', '', $t);
        $t = preg_replace('/\+?\d[\d\- ]{8,}\d/u', '', $t); // 電話番号らしき連番
        $t = preg_replace('/\[REDACTED\]|\[tel\]/u', '', $t);
        $t = trim(preg_replace('/\s+/u', ' ', (string) $t));

        return mb_substr($t, 0, $max);
    }

    /** MON-000123 → 「エム オー エヌ 1 2 3」ではなく「番号 123」と読ませる */
    private static function spellId(string $publicId): string
    {
        return '番号 '.implode(' ', str_split((string) (int) preg_replace('/\D/', '', $publicId)));
    }

    /** TwiML（読み上げ＋1 の入力待ち）。2 回繰り返す */
    public function twiml(CallAttempt $attempt, string $token): string
    {
        $n = Notification::query()->find($attempt->notification_id);
        $incidents = $n ? $this->incidentsOf($n) : [];
        $text = $incidents ? $this->sayText($incidents) : '酒丸監視のテスト発信です。'.$this->sites->clientName().' '.$this->sites->environmentLabel().' の電話通知は正しく設定されています。確認のため 1 を押してください。';
        $voice = e((string) config('monitoring.phone.voice', 'Polly.Mizuki'));
        $say = '<Say language="ja-JP" voice="'.$voice.'">'.e($text).'</Say>';
        $action = e($this->publicBase().'/internal/monitor/voice/'.$token.'/gather');

        return '<?xml version="1.0" encoding="UTF-8"?><Response><Gather numDigits="1" timeout="8" method="POST" action="'.$action.'">'.$say.'<Pause length="1"/>'.$say
            .'</Gather><Say language="ja-JP" voice="'.$voice.'">確認の入力がありませんでした。次の担当者に連絡します。</Say></Response>';
    }

    /** 1 が押されたとき。冪等。 @return string 読み上げる応答 */
    public function acknowledge(CallAttempt $attempt, string $digits, CarbonImmutable $now): string
    {
        $attempt->digits = mb_substr($digits, 0, 8);
        if ($digits !== '1') {
            $attempt->save();

            return '確認は記録されていません。';
        }
        $contact = Contact::query()->find($attempt->contact_id);
        $actor = ['id' => null, 'name' => mb_substr(($contact?->name ?? '電話担当').'（電話）', 0, 100)];
        $n = Notification::query()->find($attempt->notification_id);
        foreach ($n ? $this->incidentsOf($n) : [] as $incident) {
            if ($incident->workflow_status === WorkflowStatus::OPEN) {
                try {
                    $this->incidents->act($incident, 'acknowledge', (int) $incident->version, $actor, ['reason' => '電話で確認'], 'phone-ack:'.$attempt->id.':'.$incident->id);
                } catch (\Throwable) {
                    // 同時更新などで失敗しても通話は確認扱いにする（画面で確認可能）
                }
            }
            $this->audit->record($actor, 'incident.acknowledge_by_phone', 'incident', (string) $incident->public_id, null, ['call_attempt' => $attempt->id], '電話で確認');
        }
        $attempt->status = 'acknowledged';
        $attempt->finished_at = $now;
        $attempt->save();
        if ($n) { // 配送ワーカーがすぐ発信列を DELIVERED で閉じる
            Notification::query()->where('id', $n->id)->whereIn('status', ['PENDING', 'RETRY_WAIT'])->update(['available_at' => $now]);
        }

        return '確認を記録しました。ありがとうございました。';
    }

    /** StatusCallback（Twilio の CallStatus を反映） */
    public function status(CallAttempt $attempt, array $params, CarbonImmutable $now): void
    {
        $s = strtolower((string) ($params['CallStatus'] ?? ''));
        if ($attempt->status === 'acknowledged' || $s === '') {
            return;
        }
        $map = ['queued' => 'queued', 'initiated' => 'initiated', 'ringing' => 'ringing', 'in-progress' => 'in-progress', 'answered' => 'in-progress',
            'completed' => 'completed', 'busy' => 'busy', 'failed' => 'failed', 'no-answer' => 'no-answer', 'canceled' => 'cancelled'];
        $attempt->status = $map[$s] ?? $attempt->status;
        if (isset($params['CallDuration']) && is_numeric($params['CallDuration'])) {
            $attempt->duration_sec = (int) $params['CallDuration'];
        }
        if (in_array($attempt->status, ['completed', 'busy', 'failed', 'no-answer', 'cancelled'], true)) {
            $attempt->finished_at = $now;
            Notification::query()->where('id', $attempt->notification_id)->whereIn('status', ['PENDING', 'RETRY_WAIT'])->update(['available_at' => $now]);
        }
        $attempt->save();
    }
}
