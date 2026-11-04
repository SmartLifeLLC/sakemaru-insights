@use('App\Monitoring\Support\Icon')
@php($rows = $this->rows())
<div>
<x-monitor.shell :page="$this">
    @if (! $rows)
        <section class="panel"><div class="empty">
            資源収集ノードはまだ登録されていません。<br>
            各サーバーの .env に <span class="mono">MONITOR_NODE_CODE</span>・<span class="mono">MONITOR_NODE_ROLE</span>・<span class="mono">MONITOR_NODE_DISPLAY_NAME</span> を設定し、
            <span class="mono">sakemaru-monitor-resources@.service</span> を起動すると、最初の送信で自動登録されます。
        </div></section>
    @else
        <x-monitor.node-panels :rows="$rows" :range="$this->range" setter="setRange" />
    @endif
</x-monitor.shell>
</div>
