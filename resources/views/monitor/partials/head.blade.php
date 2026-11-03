{{-- 算盤 監視: テーマ（ライト既定・v2.1。保存キーを変えて以前のダーク保存を引き継がない）を描画前に適用し、ちらつきを防ぐ。保存失敗時は既定のまま --}}
<script>
    (function () {
        var t = 'light';
        try { t = localStorage.getItem('sakemaru-monitor-theme-v2') || 'light'; localStorage.setItem('theme', t); } catch (e) {}
        var h = document.documentElement;
        h.classList.add('mon-root');
        h.dataset.theme = t;
        h.classList.toggle('dark', t === 'dark');
    })();
</script>
@vite('resources/css/monitor/monitor.css')
