@use('App\Monitoring\Support\Icon')
@use('App\Filament\Monitor\MonitorPage', 'P')
@use('App\Monitoring\Services\FeatureRegistry')
@use('App\Monitoring\Resources\ThresholdEvaluator')
@php
    $d = $this->data();
    $tabs = ['features' => 'サービスと機能', 'rules' => 'ルール一覧', 'test' => '判定テスト', 'nodes' => 'ノードと対象', 'history' => '変更履歴'];
    $sevLabel = ['WARNING' => '警告', 'ERROR' => 'エラー', 'CRITICAL' => '重大'];
@endphp
<div>
<x-monitor.shell :page="$this">
    <x-slot:buttons>
        @if ($d['canManage'])
            {{ $this->detectAction }}
        @else
            <span class="badge neutral">閲覧のみ（変更は monitor.manage）</span>
        @endif
    </x-slot:buttons>
    <div class="tabs" role="tablist">
        @foreach ($tabs as $k => $l)
            <button type="button" role="tab" class="{{ $this->tab === $k ? 'active' : '' }}" aria-selected="{{ $this->tab === $k ? 'true' : 'false' }}" wire:click="setTab('{{ $k }}')">{{ $l }}</button>
        @endforeach
    </div>

    @if ($this->tab === 'features')
        <p class="rule-tip">{!! Icon::svg('info') !!}上位が無効なら下位は実行されず「未監視」と表示します（正常とは表示しません）。推奨値は業務設定から検出しますが、自動では切り替えません。</p>
        <section class="panel">
            <div class="tree">
                @foreach ($d['tree'] as $node)
                    @php($s = $node['site'])
                    <div class="tree-site">
                        <div class="tree-row">
                            <span><b>{{ $this->sites()->label($s->application_code) }}</b> <span class="muted">{{ config('monitoring.sites.'.$s->application_code.'.role') }}</span></span>
                            <span>@if ($s->enabled)<span class="badge ok">監視中</span>@else<span class="badge neutral">停止</span>@endif</span>
                            <span>@if ($d['canManage']){{ ($this->toggleSiteAction)(['id' => $s->id]) }}@endif</span>
                        </div>
                        @foreach ($node['features'] as $fx)
                            @php($f = $fx['feature'])
                            <div class="tree-row level-1">
                                <span>{{ $f->display_name }} <span class="code-name mono">{{ $f->feature_key }}</span>
                                    @if ($f->recommended_enabled !== null)<span class="chip {{ $f->needsReview() ? 'review' : '' }}" title="{{ $f->recommended_reason }}">{{ $f->needsReview() ? '要確認 · ' : '' }}推奨: {{ $f->recommended_enabled ? '有効' : '無効' }}（{{ \Illuminate\Support\Str::limit((string) $f->recommended_reason, 30) }}）</span>@endif
                                    @if ($f->updated_name)<span class="muted">· {{ $f->updated_name }} {{ P::jst($f->updated_at) }}</span>@endif
                                </span>
                                <span>@if (! $s->enabled)<span class="badge neutral">未監視（サービス停止）</span>@elseif ($f->enabled)<span class="badge ok">監視中</span>@else<span class="badge neutral">未監視（機能未使用）</span>@endif</span>
                                <span>@if ($d['canManage']){{ ($this->toggleFeatureAction)(['id' => $f->id]) }}@endif</span>
                            </div>
                            @foreach ($fx['checks'] as $cx)
                                @php($c = $cx['model'])
                                <div class="tree-row level-2">
                                    <span>{{ $c->display_name }} <span class="code-name mono">{{ $c->check_key }}</span> <span class="muted">{{ $c->last_completed_at ? '最終 '.P::ago($c->last_completed_at) : '' }}</span></span>
                                    <span>@if ($cx['gate']['active'])<x-monitor.outcome :outcome="(string) $c->last_outcome" />@else<span class="badge neutral">{{ FeatureRegistry::gateLabel($cx['gate']['reason']) }}</span>@endif</span>
                                    <span>@if ($d['canManage'] && $c->implemented){{ ($this->toggleCheckAction)(['id' => $c->id]) }}@endif</span>
                                </div>
                            @endforeach
                        @endforeach
                    </div>
                @endforeach
            </div>
        </section>
    @elseif ($this->tab === 'rules')
        <p class="rule-tip">{!! Icon::svg('info') !!}優先順位の高い順に実行します（同時に期限が来た場合）。「記録のみ」はログに残しますが、エラー管理・通知へは上げません。</p>
        @foreach ($d['checks'] as $siteId => $rows)
            <section class="panel table-panel wide-panel">
                <div class="panel-head"><div><h2 class="panel-title">{{ $this->siteLabel((int) $siteId) }}</h2></div></div>
                <div class="table-scroll">
                    <table class="rule-table">
                        <thead><tr><th>優先</th><th>監視項目</th><th>間隔 / 猶予</th><th>違反時の重大度</th><th>一致時の動作</th><th>状態</th><th>最終結果</th><th></th></tr></thead>
                        <tbody>
                        @foreach ($rows as $i => $x)
                            @php($c = $x['model'])
                            <tr>
                                <td><div class="priority"><span class="mono">{{ $i + 1 }}</span>@if ($d['canManage'])<span class="reorder"><button type="button" wire:click="moveCheck({{ $c->id }}, 'up')" aria-label="上へ" @disabled($i === 0)>▲</button><button type="button" wire:click="moveCheck({{ $c->id }}, 'down')" aria-label="下へ" @disabled($i === count($rows) - 1)>▼</button></span>@endif</div></td>
                                <td><div class="rule-name">{{ $c->display_name }}<small class="mono">{{ $c->check_key }}{{ $x['def']?->runbookKey ? ' · '.$x['def']->runbookKey : '' }}</small></div></td>
                                <td class="rule-condition">{{ intdiv((int) $c->interval_seconds, 60) }} 分 / {{ intdiv((int) $c->grace_seconds, 60) }} 分</td>
                                <td>@if ($c->rule_json['severity'] ?? null)<x-monitor.sev :severity="$c->rule_json['severity']" />@else<span class="text-muted">既定</span>@endif</td>
                                <td>{{ ($c->rule_json['action'] ?? 'notify') === 'record_only' ? '記録のみ' : '通知' }}</td>
                                <td>@if (! $c->implemented)<span class="badge neutral">未実装</span>@elseif ($x['gate']['active'])<span class="badge ok">監視中</span>@else<span class="badge neutral">{{ FeatureRegistry::gateLabel($x['gate']['reason']) }}</span>@endif</td>
                                <td><x-monitor.outcome :outcome="(string) $c->last_outcome" /></td>
                                <td>@if ($d['canManage'] && $c->implemented){{ ($this->editRuleAction)(['id' => $c->id]) }}@endif</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endforeach
    @elseif ($this->tab === 'test')
        <section class="panel wide-panel">
            <div class="panel-head"><div><h2 class="panel-title">判定テスト（サーバー資源）</h2><p class="panel-sub">入力した 30 秒値の並びに、しきい値と継続時間を当てはめた結果だけを返します。業務DB・監視データは読みません。</p></div></div>
            <form class="form-grid" wire:submit="runTest">
                <div><label>指標</label><select class="input" wire:model="testMetric">@foreach (ThresholdEvaluator::LABELS as $mk => $ml)@if (! in_array($mk, ['disk_used_pct', 'inode_used_pct'], true))<option value="{{ $mk }}">{{ $ml }}</option>@endif @endforeach</select></div>
                <div style="grid-column:span 2"><label>30 秒ごとの値（古い順、カンマ区切り）</label><input class="input mono" wire:model="testValues"></div>
                <div><label>警告（空欄は既定）</label><input class="input" wire:model="testWarning" inputmode="decimal"></div>
                <div><label>エラー（空欄は既定）</label><input class="input" wire:model="testError" inputmode="decimal"></div>
                <div><label>継続（秒・空欄は既定）</label><input class="input" wire:model="testDuration" inputmode="numeric"></div>
                <div style="align-self:end"><button type="submit" class="btn primary">判定する</button></div>
            </form>
            @if ($this->testResult)
                <div class="evaluation-list">
                    @if (isset($this->testResult['error']))
                        <div class="flash error">{{ $this->testResult['error'] }}</div>
                    @else
                        @php($r = $this->testResult)
                        <div class="evaluation-step"><span class="num">1</span><div><b>入力</b><p>{{ $r['points'] }} 点（{{ intdiv($r['span'], 60) }} 分 {{ $r['span'] % 60 }} 秒分）</p></div></div>
                        <div class="evaluation-step"><span class="num">2</span><div><b>継続時間</b><p>{{ $r['duration'] ? intdiv($r['duration'], 60).' 分間すべての値が超えた場合に判定' : '最新値で即時判定' }}</p></div></div>
                        <div class="evaluation-step"><span class="num">3</span><div><b>結果</b><p>{{ $r['threshold'] !== null ? 'しきい値 '.$r['threshold'].' を満たしました' : 'しきい値を満たしていません（データが継続時間に満たない場合も正常）' }}</p></div><span class="badge {{ $r['level'] === 'ok' ? 'ok' : ($r['level'] === 'warning' ? 'warn' : 'error') }}">{{ ['ok' => '正常', 'warning' => '警告', 'error' => 'エラー', 'critical' => '重大'][$r['level']] }}</span></div>
                    @endif
                </div>
            @endif
        </section>
    @elseif ($this->tab === 'nodes')
        <section class="panel table-panel wide-panel">
            <div class="panel-head"><div><h2 class="panel-title">ノード台帳</h2><p class="panel-sub">各サーバーの .env から自動登録。変更できるのは表示名・有効/無効・しきい値だけ</p></div></div>
            <div class="table-scroll"><table><thead><tr><th>ノードコード</th><th>表示名</th><th>役割</th><th>状態</th><th>最終受信</th><th>しきい値の上書き</th><th></th></tr></thead><tbody>
            @forelse ($d['nodes'] as $n)
                <tr><td class="mono">{{ $n->node_code }}</td><td>{{ $n->display_name }}</td><td>{{ $n->role }}</td><td>{!! $n->enabled ? '<span class="badge ok">有効</span>' : '<span class="badge neutral">無効</span>' !!}</td><td>{{ P::ago($n->last_heartbeat_at) }}</td>
                    <td class="mono" style="font-size:9px">{{ $n->thresholds_json ? collect($n->thresholds_json)->map(fn ($v, $k) => $k.': '.json_encode($v))->implode(' / ') : '既定' }}</td>
                    <td>@if ($d['canManage']){{ ($this->editNodeAction)(['id' => $n->id]) }}@endif</td></tr>
            @empty
                <tr><td colspan="7"><div class="empty">ノードはまだありません（資源収集の初回送信で登録されます）。</div></td></tr>
            @endforelse
            </tbody></table></div>
        </section>
        <section class="panel table-panel wide-panel">
            <div class="panel-head"><div><h2 class="panel-title">HTTP プローブ</h2><p class="panel-sub">https のみ・顧客の自サービスのみ。60 秒ごと・タイムアウト 5 秒・連続 3 回でエラー</p></div>@if ($d['canManage']){{ $this->addProbeAction }}@endif</div>
            <div class="table-scroll"><table><thead><tr><th>サービス</th><th>表示名</th><th>URL</th><th>期待</th><th>状態</th><th>更新</th><th></th></tr></thead><tbody>
            @forelse ($d['probes'] as $t)
                <tr><td>{{ $this->siteLabel((int) $t->site_id) }}</td><td>{{ $t->display_name }}</td><td class="mono">{{ $t->params_json['url'] ?? '' }}</td><td class="mono">{{ $t->params_json['expect_status'] ?? 200 }}</td><td>{!! $t->enabled ? '<span class="badge ok">有効</span>' : '<span class="badge neutral">停止</span>' !!}</td><td>{{ $t->updated_name }} {{ P::jst($t->updated_at) }}</td><td>@if ($d['canManage']){{ ($this->editProbeAction)(['id' => $t->id]) }}@endif</td></tr>
            @empty
                <tr><td colspan="7"><div class="empty">HTTP プローブは登録されていません。</div></td></tr>
            @endforelse
            </tbody></table></div>
        </section>
        <section class="panel table-panel">
            <div class="panel-head"><div><h2 class="panel-title">アプリログの収集器</h2><p class="panel-sub">配置設定（config/monitoring.php collectors）で固定。ログパスは画面から登録しません</p></div></div>
            <div class="table-scroll"><table><thead><tr><th>サービス</th><th>ノード</th><th>ログファイル</th><th>署名鍵（env 名）</th></tr></thead><tbody>
            @forelse ($d['collectors'] as $col)
                <tr><td>{{ $this->sites()->label($col['application_code'] ?? '?') }}</td><td class="mono">{{ $col['node_code'] ?? '' }}</td><td class="mono" style="font-size:9px">{{ implode(', ', (array) ($col['log_files'] ?? [])) }}</td><td class="mono">{{ $col['secret_env'] ?? '' }}</td></tr>
            @empty
                <tr><td colspan="4"><div class="empty">収集器は設定されていません。</div></td></tr>
            @endforelse
            </tbody></table></div>
        </section>
    @else
        <section class="panel table-panel">
            <div class="panel-head"><div><h2 class="panel-title">変更履歴</h2><p class="panel-sub">最新 100 件（監査。秘密値は記録しません）</p></div></div>
            <div class="table-scroll"><table><thead><tr><th>日時</th><th>操作者</th><th>操作</th><th>対象</th><th>理由</th><th>変更内容</th></tr></thead><tbody>
            @forelse ($d['audit'] as $a)
                <tr><td class="mono">{{ P::jst($a->created_at, 'm/d H:i:s') }}</td><td>{{ $a->actor_name }}</td><td class="mono">{{ $a->action }}</td><td class="mono">{{ $a->target_type }}:{{ $a->target_id }}</td><td class="summary-cell" title="{{ $a->reason }}">{{ $a->reason }}</td><td class="summary-cell mono" style="font-size:9px" title="{{ json_encode($a->after_json, JSON_UNESCAPED_UNICODE) }}">{{ \Illuminate\Support\Str::limit(json_encode($a->after_json, JSON_UNESCAPED_UNICODE), 80) }}</td></tr>
            @empty
                <tr><td colspan="6"><div class="empty">変更履歴はありません。</div></td></tr>
            @endforelse
            </tbody></table></div>
        </section>
    @endif
</x-monitor.shell>
</div>
