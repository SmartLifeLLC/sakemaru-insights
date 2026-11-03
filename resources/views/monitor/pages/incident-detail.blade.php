@use('App\Monitoring\Support\Icon')
@use('App\Monitoring\Support\Ui')
@use('App\Filament\Monitor\MonitorPage', 'P')
@use('App\Filament\Monitor\Pages\Incidents')
@use('App\Filament\Monitor\Pages\Logs')
@use('App\Filament\Monitor\Pages\Runbooks')
@php
    $d = $this->data();
    $i = $d['incident'];
    $now = $d['now'];
    $open = $i->workflow_status->isOpenish();
    $snoozed = $i->snoozed_until && $i->snoozed_until->greaterThan($now);
    $byRoute = $d['notifications']->groupBy(fn ($n) => in_array($n->route_key, ['mail', 'phone'], true) ? $n->route_key : 'slack');
    $routeState = fn ($k) => ($n = ($byRoute[$k] ?? collect())->first()) ? $n->status : null;
    $eventLabel = fn ($t) => match ($t) { 'DETECTED' => '検知', 'RECURRED' => '再発', 'REACTIVATED' => '再検知', 'ESCALATED' => '重大度上昇', 'RECOVERED' => '復旧（自動確認）', 'UNKNOWN' => '検査不能', 'ACKNOWLEDGE' => '確認', 'ASSIGN' => '担当割当', 'START' => '対応開始', 'NOTE' => 'メモ', 'SNOOZE' => '通知抑止', 'UNSNOOZE' => '抑止解除', 'MARK_RECOVERED' => '復旧確認（人手）', 'RESOLVE' => '解決', 'CLOSE' => '終了', 'REOPEN' => '再オープン', 'SUPPRESSION_EXPIRED' => '抑止期限切れ', default => $t };
    $callLabel = fn ($s) => match ($s) { 'acknowledged' => '確認（1）', 'no-answer' => '応答なし', 'busy' => '話し中', 'failed', 'error' => '失敗', 'completed' => '応答（未確認）', 'queued', 'scheduled', 'initiated' => '発信中', 'ringing' => '呼出中', 'in-progress' => '通話中', 'cancelled' => '取消', 'no-callback' => '結果不明', default => $s };
@endphp
<div>
<x-monitor.shell :page="$this">
    <x-slot:buttons>
        <a class="btn" href="{{ Incidents::getUrl() }}">{!! Icon::svg('arrow', '') !!}エラー管理へ</a>
    </x-slot:buttons>

    <section class="panel" style="padding:20px;margin-bottom:20px">
        <div class="actions-row" style="align-items:center">
            <span class="mono" style="font-size:15px;font-weight:600">{{ $i->public_id }}</span>
            <x-monitor.sev :severity="$i->severity" />
            <span class="badge neutral">{{ $this->siteLabel((int) $i->site_id) }}</span>
            @if ($i->generation > 1)<span class="badge neutral">再発 第{{ $i->generation }}世代</span>@endif
            @if ($snoozed)<span class="badge warn">通知抑止中 {{ P::jst($i->snoozed_until) }}まで</span>@endif
        </div>
        <h2 style="font-size:18px;margin:12px 0 6px;font-weight:600">{{ $i->title }}</h2>
        <div class="code-name mono">{{ $i->check_key ?: $i->error_code }} · 指紋 {{ substr($i->fingerprint, 0, 16) }}…</div>
        <div class="actions-row" style="margin-top:16px">
            @if ($d['canRespond'])
                @if ($open)
                    @if ($i->workflow_status->value === 'OPEN'){{ $this->acknowledgeAction }}@endif
                    {{ $this->assignAction }}
                    @if ($i->workflow_status->value !== 'IN_PROGRESS'){{ $this->startAction }}@endif
                    {{ $this->noteAction }}
                    @if ($i->check_id){{ $this->recheckAction }}@endif
                    @if ($snoozed){{ $this->unsnoozeAction }}@else{{ $this->snoozeAction }}@endif
                    @if ($i->observation_state->value !== 'RECOVERED'){{ $this->markRecoveredAction }}@else{{ $this->resolveIncidentAction }}@endif
                @elseif ($i->workflow_status->value === 'RESOLVED')
                    {{ $this->noteAction }}{{ $this->closeAction }}{{ $this->reopenAction }}
                @else
                    {{ $this->reopenAction }}
                @endif
            @else
                <span class="text-muted" style="font-size:11px">閲覧のみ（対応操作の権限がありません）</span>
            @endif
        </div>
    </section>

    <div class="facts">
        <div class="fact"><span>何が異常か</span><b><span class="badge {{ Ui::tone($i->observation_state) === 'ok' ? 'ok' : (Ui::tone($i->observation_state) === 'warning' ? 'warn' : 'error') }}">{{ $i->observation_state->label() }}</span></b><small>初回 {{ P::jst($i->first_seen_at) }} · 最終 {{ P::jst($i->last_seen_at) }} · {{ number_format($i->occurrence_count) }}回{{ $i->recovered_at ? ' · 復旧 '.P::jst($i->recovered_at) : '' }}</small></div>
        <div class="fact"><span>影響範囲</span><b>{{ $i->business_date ? '対象営業日 '.$i->business_date->format('Y-m-d') : ($i->node_code ? 'ノード '.$i->node_code : '—') }}</b><small>@foreach ((array) $i->scope_json as $sk => $sv)@if ($sk !== 'business_date'){{ $sk }}: {{ is_array($sv) ? (count($sv) > 8 ? count($sv).'件' : implode(', ', $sv)) : $sv }} @endif @endforeach</small></div>
        <div class="fact"><span>確認済みか</span><b>{{ $i->workflow_status->label() }}</b><small>{{ $i->acknowledged_at ? $i->acknowledged_name.' が '.P::jst($i->acknowledged_at).' に確認' : ($open ? '未確認 '.Ui::duration((int) \Carbon\CarbonImmutable::instance($i->first_seen_at)->diffInSeconds($now)) : '') }}</small></div>
        <div class="fact"><span>誰が対応中か</span><b>{{ $i->assigned_name ?: '未割当' }}</b><small>{{ $i->resolved_at ? '解決 '.P::jst($i->resolved_at) : ($i->next_reminder_at && $open ? '次回通知 '.P::jst($i->next_reminder_at) : '') }}</small></div>
        <div class="fact"><span>通知が届いたか</span>
            <b class="actions-row" style="gap:5px">
                @foreach (['slack' => 'Slack', 'mail' => 'メール', 'phone' => '電話'] as $rk => $rl)
                    @if ($st = $routeState($rk))
                        <span class="badge {{ $st->value === 'DELIVERED' ? 'ok' : (in_array($st->value, ['BLOCKED', 'UNANSWERED'], true) ? 'error' : 'neutral') }}">{{ $rl }} {{ $st->label() }}</span>
                    @endif
                @endforeach
                @if ($d['notifications']->isEmpty())<span class="text-muted">通知なし</span>@endif
            </b>
            <small>{{ $i->last_delivered_at ? 'Slack 最終送信 '.P::jst($i->last_delivered_at).'（応答成功。既読ではありません）' : '' }}</small>
        </div>
    </div>

    <div class="two-col">
        <div style="display:flex;flex-direction:column;gap:20px;min-width:0">
            <section class="panel">
                <div class="panel-head"><div><h2 class="panel-title">証拠（伏字済み）</h2><p class="panel-sub">{{ isset($i->evidence_snapshot_json['captured_at']) ? '取得 '.P::jst($i->evidence_snapshot_json['captured_at']) : '' }}</p></div></div>
                <div style="padding:14px 20px 18px">
                    @php($m = $i->evidence_snapshot_json['metrics'] ?? [])
                    @if ($m)
                        <div class="kv" style="padding:0 0 12px">@foreach ($m as $mk => $mv)<span>{{ $mk }}</span><b class="mono">{{ is_scalar($mv) ? $mv : json_encode($mv, JSON_UNESCAPED_UNICODE) }}</b>@endforeach</div>
                    @endif
                    @php($ev = $i->evidence_snapshot_json['evidence'] ?? [])
                    @if ($ev)
                        <div class="mono-box">{{ json_encode($ev, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</div>
                    @elseif (! $m)
                        <span class="text-muted">証拠はありません。</span>
                    @endif
                    @if ($i->resolution_json)
                        <div class="detail-block"><h3>解決記録</h3><div class="kv" style="padding:0"><span>原因分類</span><b>{{ $i->resolution_json['cause_category'] ?? '' }}</b><span>実施内容</span><b style="white-space:pre-wrap">{{ $i->resolution_json['action_taken'] ?? '' }}</b><span>検証結果</span><b style="white-space:pre-wrap">{{ $i->resolution_json['verification'] ?? '' }}</b><span>記録者</span><b>{{ $i->resolution_json['resolved_by'] ?? '' }}</b></div></div>
                    @endif
                </div>
            </section>
            <section class="panel">
                <div class="panel-head"><div><h2 class="panel-title">発生・操作タイムライン</h2><p class="panel-sub">新しい順 · 最新 {{ $d['events']->count() }} 件</p></div></div>
                <div class="timeline">
                    @foreach ($d['events'] as $e)
                        <div class="timeline-item"><time class="mono">{{ P::jst($e->created_at, 'm/d H:i:s') }}</time><div><b>{{ $eventLabel($e->event_type) }}</b>@if ($e->actor_name) <span class="text-muted">— {{ $e->actor_name }}</span>@endif @if ($e->generation > 1)<span class="text-muted">（第{{ $e->generation }}世代）</span>@endif @if ($e->reason)<div class="text-muted" style="white-space:pre-wrap">{{ $e->reason }}</div>@endif</div></div>
                    @endforeach
                </div>
            </section>
            <section class="panel table-panel">
                <div class="panel-head"><div><h2 class="panel-title">関連する監視ログ</h2><p class="panel-sub">最新 20 件</p></div><a class="btn link" href="{{ Logs::getUrl(['incident' => $i->public_id, 'from' => \Carbon\CarbonImmutable::instance($i->first_seen_at)->setTimezone('Asia/Tokyo')->subHour()->format('Y-m-d\TH:i')]) }}">監視ログで開く {!! Icon::svg('arrow') !!}</a></div>
                @include('monitor.partials.log-table', ['rows' => $d['logs'], 'clickable' => false])
            </section>
        </div>
        <div style="display:flex;flex-direction:column;gap:20px;min-width:0">
            <section class="panel">
                <div class="panel-head"><div><h2 class="panel-title">対処手順 {{ $d['runbook']?->runbook_key }}</h2><p class="panel-sub">{{ $d['runbook']?->title }}</p></div>@if ($d['runbook'])<a class="btn link" href="{{ Runbooks::getUrl(['key' => $d['runbook']->runbook_key]) }}">全文 {!! Icon::svg('arrow') !!}</a>@endif</div>
                <div style="padding:12px 20px 18px">
                    @if ($d['runbook'])
                        <div class="detail-block"><h3>最初に確認するもの</h3><pre>{{ $d['runbook']->first_checks }}</pre></div>
                        <div class="detail-block"><h3>対処方針</h3><pre>{{ $d['runbook']->decision_steps }}</pre></div>
                        <div class="detail-block"><h3>復旧条件</h3><pre>{{ $d['runbook']->verification_steps }}</pre></div>
                    @else
                        <span class="text-muted">この案件に対応する手順は登録されていません。</span>
                    @endif
                </div>
            </section>
            @if ($d['check'])
                <section class="panel table-panel">
                    <div class="panel-head"><div><h2 class="panel-title">再確認（読取り専用）</h2><p class="panel-sub">チェック {{ $d['check']->check_key }}</p></div></div>
                    @if ($d['rechecks']->isEmpty())
                        <div class="empty" style="padding:24px">予約はありません。</div>
                    @else
                        <div class="table-scroll"><table><thead><tr><th>予約</th><th>状態</th><th>結果</th><th>メッセージ</th></tr></thead><tbody>
                        @foreach ($d['rechecks'] as $r)
                            <tr><td class="mono">{{ P::jst($r->requested_at) }}<br><small class="text-muted">{{ $r->requested_name }}</small></td><td>{{ Ui::label($r->status) }}</td><td>@if ($r->result_outcome)<x-monitor.outcome :outcome="$r->result_outcome" />@endif</td><td class="summary-cell">{{ $r->message }}</td></tr>
                        @endforeach
                        </tbody></table></div>
                    @endif
                </section>
            @endif
            <section class="panel table-panel">
                <div class="panel-head"><div><h2 class="panel-title">通知と試行履歴</h2><p class="panel-sub">Slack・メール・電話</p></div></div>
                <div class="table-scroll"><table><thead><tr><th>番号</th><th>経路</th><th>種別</th><th>状態</th><th>作成 / 送信</th><th>試行</th></tr></thead><tbody>
                @forelse ($d['notifications'] as $n)
                    <tr>
                        <td class="mono">N-{{ $n->id }}</td>
                        <td>{{ ['mail' => 'メール', 'phone' => '電話'][$n->route_key] ?? 'Slack' }}</td>
                        <td>{{ $n->kind->label() }}</td>
                        <td><span class="badge {{ $n->status->value === 'DELIVERED' ? 'ok' : (in_array($n->status->value, ['BLOCKED', 'UNANSWERED'], true) ? 'error' : 'neutral') }}">{{ $n->status->label() }}</span>@if ($n->last_error_code)<br><small class="text-muted mono">{{ $n->last_error_code }}</small>@endif</td>
                        <td class="mono">{{ P::jst($n->created_at) }}<br><small class="text-muted">{{ $n->delivered_at ? P::jst($n->delivered_at) : ($n->status->value === 'RETRY_WAIT' ? '次回 '.P::jst($n->available_at, 'H:i') : '') }}</small></td>
                        <td class="mono" style="font-size:9px">@foreach (($d['attempts'][$n->id] ?? collect()) as $a)<div>#{{ $a->attempt_no }} {{ P::jst($a->started_at, 'H:i:s') }} {{ $a->result }}{{ $a->http_status ? ' '.$a->http_status : '' }}</div>@endforeach</td>
                    </tr>
                @empty
                    <tr><td colspan="6"><div class="empty">通知はまだ作成されていません。</div></td></tr>
                @endforelse
                </tbody></table></div>
            </section>
            @if ($d['calls']->isNotEmpty())
                <section class="panel table-panel">
                    <div class="panel-head"><div><h2 class="panel-title">電話の発信履歴</h2><p class="panel-sub">誰に・いつ・結果・確認の有無</p></div></div>
                    <div class="table-scroll"><table><thead><tr><th>発信</th><th>担当者</th><th>巡回</th><th>結果</th><th>通話</th></tr></thead><tbody>
                    @foreach ($d['calls'] as $c)
                        <tr><td class="mono">{{ P::jst($c->started_at ?? $c->scheduled_at, 'm/d H:i:s') }}</td><td>{{ $d['contacts'][$c->contact_id] ?? '—' }}</td><td>{{ $c->round_no }} 巡目</td><td><span class="badge {{ $c->status === 'acknowledged' ? 'ok' : (in_array($c->status, ['failed', 'error', 'no-answer', 'busy'], true) ? 'warn' : 'neutral') }}">{{ $callLabel($c->status) }}</span></td><td>{{ $c->duration_sec !== null ? $c->duration_sec.' 秒' : '—' }}</td></tr>
                    @endforeach
                    </tbody></table></div>
                </section>
            @endif
        </div>
    </div>
</x-monitor.shell>
</div>
