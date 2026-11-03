@use('App\Monitoring\Support\Icon')
@php($d = $this->data())
<div>
<x-monitor.shell :page="$this">
    <div class="runbook-list">
        @forelse ($d['list'] as $rb)
            <section class="panel runbook-card">
                <div class="actions-row"><span class="badge blue mono">{{ $rb->runbook_key }}</span><span class="badge neutral">v{{ $rb->version }}</span>@foreach (array_slice($d['checks'][$rb->runbook_key] ?? [], 0, 3) as $ck)<span class="chip mono">{{ $ck }}</span>@endforeach</div>
                <h3>{{ $rb->title }}</h3>
                <p>{{ \Illuminate\Support\Str::limit($rb->symptoms, 140) }}</p>
                <button type="button" class="btn" wire:click="select('{{ $rb->runbook_key }}')">{!! Icon::svg('book') !!}手順を開く</button>
            </section>
        @empty
            <div class="empty">手順が登録されていません。</div>
        @endforelse
    </div>
    <x-slot:drawer>
        @php($c = $d['current'])
        <x-monitor.drawer :open="(bool) $c" :title="$c ? $c->runbook_key.' '.$c->title : ''">
            @if ($c)
                @foreach (['symptoms' => '症状', 'impact' => '影響', 'first_checks' => '最初に確認するもの', 'read_only_checks' => '読取り専用の確認', 'decision_steps' => '判断と分岐', 'recovery_procedure' => '復旧手順', 'verification_steps' => '検証（復旧条件）', 'escalation' => 'エスカレーション', 'prevention_notes' => '再発防止'] as $f => $label)
                    @if (filled($c->{$f}))
                        <div class="detail-block"><h3>{{ $label }}</h3><pre>{{ $c->{$f} }}</pre></div>
                    @endif
                @endforeach
                <p class="form-note">版 v{{ $c->version }} · 確認日 {{ \App\Filament\Monitor\MonitorPage::jst($c->reviewed_at, 'Y/m/d') }}</p>
            @endif
        </x-monitor.drawer>
    </x-slot:drawer>
</x-monitor.shell>
</div>
