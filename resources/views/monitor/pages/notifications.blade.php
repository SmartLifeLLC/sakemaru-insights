@use('App\Monitoring\Support\Icon')
@use('App\Filament\Monitor\MonitorPage', 'P')
@php
    $d = $this->data();
    $s = $d['sum'];
    $rate = fn ($ok, $ng) => ($ok + $ng) ? round($ok / ($ok + $ng) * 100, 1).'%' : '—';
    $callLabel = fn ($st) => match ($st) { 'acknowledged' => '確認（1）', 'no-answer' => '応答なし', 'busy' => '話し中', 'failed', 'error' => '失敗', 'completed' => '応答（未確認）', 'queued', 'scheduled', 'initiated' => '発信中', 'ringing' => '呼出中', 'in-progress' => '通話中', 'cancelled' => '取消', 'no-callback' => '結果不明', default => $st };
@endphp
<div>
<x-monitor.shell :page="$this">
    <x-slot:buttons>
        @if ($d['admin']){{ $this->secretsAction }}@else<span class="badge neutral">変更は配送管理者のみ</span>@endif
    </x-slot:buttons>
    @if ($d['global_disabled'])
        <div class="alert-strip">{!! Icon::svg('alert') !!}<div><b>通知の一時停止中</b><span>{{ config('monitoring.notification.global_disable_reason') ?: '理由未記入' }}（未送信の通知は保持されています）</span></div></div>
    @endif

    <div class="notification-grid">
        <section class="panel notification-card">
            {!! Icon::svg('message') !!}
            <h3>Slack</h3>
            <p>エラー・重大の新規・再発・昇格・継続・復旧。チャンネル {{ $d['slack']['name'] }}</p>
            @if ($d['slack']['source'])<span class="badge ok">{{ $d['slack']['source'] === 'screen' ? '画面の設定を使用中' : '環境変数を使用中' }}</span>@else<span class="badge error">Webhook 未設定</span>@endif
            <div class="kv" style="padding:0 0 14px"><span>24h 送信</span><b>{{ number_format($s['slack_delivered'] ?? 0) }}</b><span>24h 失敗</span><b>{{ number_format($s['slack_blocked'] ?? 0) }}</b><span>成功率</span><b>{{ $rate($s['slack_delivered'] ?? 0, $s['slack_blocked'] ?? 0) }}</b></div>
            @if ($d['admin'])<button type="button" class="btn" wire:click="runTest('slack')">テスト通知</button>@endif
        </section>
        <section class="panel notification-card">
            {!! Icon::svg('mail') !!}
            <h3>メール</h3>
            <p>24 時間。宛先 {{ implode(', ', (array) $d['mail']['recipients']) }}。Slack が送れないときの代替にも使います。</p>
            @if (! $d['mail']['enabled'])<span class="badge neutral">停止</span>@elseif ($d['smtp_ok'])<span class="badge ok">有効</span>@else<span class="badge warn">SMTP 未設定（送信をスキップ）</span>@endif
            <div class="kv" style="padding:0 0 14px"><span>24h 送信</span><b>{{ number_format($s['mail_delivered'] ?? 0) }}</b><span>24h 失敗</span><b>{{ number_format($s['mail_blocked'] ?? 0) }}</b><span>継続 / 復旧</span><b>{{ $d['mail']['include_reminder'] ? '送る' : '送らない' }} / {{ $d['mail']['include_recovery'] ? '送る' : '送らない' }}</b><span>多発防止</span><b style="font-weight:400;font-size:10px">{{ $d['mail']['min_interval_minutes'] }} 分に 1 通にまとめ · 1 時間 {{ $d['mail']['max_per_hour'] }} 通まで</b></div>
            @if ($d['admin'])<div class="actions-row">{{ $this->mailSettingsAction }}<button type="button" class="btn" wire:click="runTest('smtp_connection')">接続テスト</button><button type="button" class="btn" wire:click="runTest('mail')">テスト送信</button></div>@endif
        </section>
        <section class="panel notification-card">
            {!! Icon::svg('phone') !!}
            <h3>電話（Twilio）</h3>
            <p>{{ $d['phone']['window_from'] }}–{{ $d['phone']['window_to'] }} JST の {{ implode('・', array_map(fn ($v) => ['ERROR' => 'エラー', 'CRITICAL' => '重大'][$v] ?? $v, (array) $d['phone']['severities'])) }}。名簿の順に発信し、1 で確認。</p>
            @if (! $d['phone']['enabled'])<span class="badge neutral">停止</span>
            @elseif (! $d['phone_allowed'])<span class="badge warn">この環境では発信しません（MONITOR_PHONE_ALLOW_ENV）</span>
            @elseif (! $d['twilio_ok'])<span class="badge warn">Twilio 未設定（発信をスキップ）</span>
            @elseif ($d['contacts']->where('enabled', true)->isEmpty())<span class="badge warn">担当者が未登録</span>
            @else<span class="badge ok">有効</span>@endif
            @php($t = $d['tonight'])
            <div class="kv" style="padding:0 0 14px">
                <span>24h 確認</span><b>{{ number_format($s['phone_delivered'] ?? 0) }}</b>
                <span>24h 未応答</span><b>{{ number_format($s['phone_unanswered'] ?? 0) }}</b>
                <span>今夜の発信列</span><b class="{{ $t['max_columns'] && $t['columns'] >= $t['max_columns'] ? 'text-red' : '' }}">{{ $t['columns'] }}{{ $t['max_columns'] ? ' / '.$t['max_columns'] : '' }}{{ $t['max_columns'] && $t['columns'] >= $t['max_columns'] ? '（上限到達・電話停止中）' : '' }}</b>
                @if ($t['cooldown_until'])<span>次の発信</span><b>{{ P::jst($t['cooldown_until'], 'H:i') }} 以降（間隔中）</b>@endif
                <span>多発防止</span><b style="font-weight:400;font-size:10px">猶予 {{ $d['phone']['delay_minutes'] }} 分 · 間隔 {{ $d['phone']['cooldown_minutes'] }} 分 · 同一案件 {{ $d['phone']['per_incident_cooldown_hours'] }} 時間 · 1 人 {{ $d['phone']['max_calls_per_contact_per_window'] ?: '∞' }} 回/晩</b>
            </div>
            @if ($d['admin'])<div class="actions-row">{{ $this->phoneSettingsAction }}<button type="button" class="btn" wire:click="runTest('twilio_connection')">接続テスト</button></div>@endif
        </section>
    </div>

    <div class="two-col">
        <section class="panel table-panel">
            <div class="panel-head"><div><h2 class="panel-title">担当者名簿（電話）</h2><p class="panel-sub">上から順に発信。応答なし・不在は 2 分後に次の担当者、1 巡で確認がなければ 15 分後に再度（最大 3 巡）</p></div>@if ($d['admin']){{ $this->contactAction }}@endif</div>
            <div class="table-scroll"><table><thead><tr><th>順</th><th>氏名</th><th>電話番号</th><th>在席</th><th>状態</th><th></th></tr></thead><tbody>
            @forelse ($d['contacts'] as $i => $c)
                <tr>
                    <td><div class="priority"><span class="mono">{{ $i + 1 }}</span>@if ($d['admin'])<span class="reorder"><button type="button" wire:click="moveContact({{ $c->id }}, 'up')" @disabled($i === 0) aria-label="上へ">▲</button><button type="button" wire:click="moveContact({{ $c->id }}, 'down')" @disabled($i === count($d['contacts']) - 1) aria-label="下へ">▼</button></span>@endif</div></td>
                    <td>{{ $c->name }}@if (($d['tonight']['calls_by_contact'][$c->id] ?? 0) > 0)<br><small class="text-muted">今夜 {{ $d['tonight']['calls_by_contact'][$c->id] }} 回{{ $d['phone']['max_calls_per_contact_per_window'] && $d['tonight']['calls_by_contact'][$c->id] >= $d['phone']['max_calls_per_contact_per_window'] ? '（上限）' : '' }}</small>@endif</td><td class="mono">{{ $c->maskedPhone() }}</td><td>{{ $c->active_from && $c->active_to ? $c->active_from.'–'.$c->active_to : '常時' }}</td>
                    <td>{!! $c->enabled ? '<span class="badge ok">発信対象</span>' : '<span class="badge neutral">停止</span>' !!}</td>
                    <td>@if ($d['admin'])<div class="actions-row">{{ ($this->contactAction)(['id' => $c->id]) }}<button type="button" class="btn" style="padding:3px 8px" wire:click="runTest('phone', {{ $c->id }})" wire:confirm="{{ $c->name }} さんへテスト発信します。よろしいですか？">テスト発信</button></div>@endif</td>
                </tr>
            @empty
                <tr><td colspan="6"><div class="empty">担当者は登録されていません。</div></td></tr>
            @endforelse
            </tbody></table></div>
        </section>
        <section class="panel">
            <div class="panel-head"><div><h2 class="panel-title">通知配送の状態</h2><p class="panel-sub">直近 24 時間（Slack・メール・電話の合計）</p></div></div>
            <div class="stat-strip">
                <div><b>{{ number_format($s['queued'] ?? 0) }}</b><span>送信対象</span></div>
                <div><b>{{ number_format($s['delivered'] ?? 0) }}</b><span>完了</span></div>
                <div><b class="{{ ($s['blocked'] ?? 0) ? 'text-red' : '' }}">{{ number_format($s['blocked'] ?? 0) }}</b><span>失敗</span></div>
                <div><b>{{ $rate($s['delivered'] ?? 0, $s['blocked'] ?? 0) }}</b><span>成功率</span></div>
            </div>
            <div class="notify-setting"><div><b>同一障害をまとめる</b><p>同じ指紋の再検知は 1 件の案件に集約し、同時多発は 1 通にまとめて送ります（電話は 5 件以上で 1 本）。</p></div><span class="badge ok">有効</span></div>
            <div class="notify-setting"><div><b>復旧時にも通知</b><p>Slack は常に送信。メールは「メール設定」で選択。</p></div><span class="badge {{ $d['mail']['include_recovery'] ? 'ok' : 'neutral' }}">メール {{ $d['mail']['include_recovery'] ? '送る' : '送らない' }}</span></div>
            <div class="notify-setting"><div><b>夜間の警告抑制</b><p>警告はもともと通知しません。エラー・重大は 24 時間通知します。</p></div><span class="badge neutral">採用しない</span></div>
        </section>
    </div>

    @if ($d['admin'])
        <section class="panel table-panel wide-panel">
            <div class="panel-head"><div><h2 class="panel-title">接続情報</h2><p class="panel-sub">配送管理者だけが閲覧できます。秘密値は末尾 4 文字だけ表示します。</p></div></div>
            @foreach ($d['secrets'] as $k => $x)
                <div class="secret-row"><span>{{ $x['label'] }}<br><span class="src mono">{{ $k }}</span></span><span class="mono">{{ $x['display'] ?? '未設定' }}</span><span class="src">{{ ['screen' => '画面の設定', 'env' => '環境変数 '.$x['env']][$x['source']] ?? '未設定' }}</span></div>
            @endforeach
        </section>
    @endif

    <section class="panel table-panel">
        <div class="panel-head"><div><h2 class="panel-title">発信履歴</h2><p class="panel-sub">最新 20 件 · 誰に・いつ・結果・確認の有無</p></div></div>
        <div class="table-scroll"><table><thead><tr><th>発信</th><th>担当者</th><th>通知</th><th>巡回</th><th>結果</th><th>通話</th><th>エラー</th></tr></thead><tbody>
        @forelse ($d['calls'] as $c)
            <tr><td class="mono">{{ P::jst($c->started_at ?? $c->scheduled_at, 'm/d H:i:s') }}</td><td>{{ $d['contactNames'][$c->contact_id] ?? '—' }}</td><td class="mono">N-{{ $c->notification_id }}</td><td>{{ $c->round_no }}</td><td><span class="badge {{ $c->status === 'acknowledged' ? 'ok' : (in_array($c->status, ['failed', 'error', 'no-answer', 'busy'], true) ? 'warn' : 'neutral') }}">{{ $callLabel($c->status) }}</span></td><td>{{ $c->duration_sec !== null ? $c->duration_sec.' 秒' : '—' }}</td><td class="mono" style="font-size:9px">{{ $c->sanitized_error }}</td></tr>
        @empty
            <tr><td colspan="7"><div class="empty">発信履歴はありません。</div></td></tr>
        @endforelse
        </tbody></table></div>
    </section>
</x-monitor.shell>
</div>
