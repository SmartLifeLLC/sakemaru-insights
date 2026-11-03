{{-- 算盤 監視: テーマ（ダーク既定）を描画前に適用し、ちらつきを防ぐ。保存失敗時は既定のまま --}}
<script>
    (function () {
        var t = 'dark';
        try { t = localStorage.getItem('sakemaru-monitor-theme') || 'dark'; localStorage.setItem('theme', t); } catch (e) {}
        var h = document.documentElement;
        h.classList.add('mon-root');
        h.dataset.theme = t;
        h.classList.toggle('dark', t === 'dark');
    })();
</script>
@vite('resources/css/monitor/monitor.css')
