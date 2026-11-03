{{-- 算盤 監視: テーマ（ライト既定・v2.1）を描画前に適用し、ちらつきを防ぐ。保存失敗時は既定のまま。
     wire:navigate の遷移では <html> の属性が差し替わり head の同一スクリプトは再実行されないため、livewire:navigated でも再適用する --}}
<script data-navigate-once>
    (function () {
        function apply() {
            var t = 'light';
            try { t = localStorage.getItem('sakemaru-monitor-theme-v2') || 'light'; localStorage.setItem('theme', t); } catch (e) {}
            var h = document.documentElement;
            h.classList.add('mon-root');
            h.dataset.theme = t;
            h.classList.toggle('dark', t === 'dark');
        }
        apply();
        window.sakemaruMonitorApplyTheme = apply;
        if (! window.__sakemaruMonitorThemeHooked) {
            window.__sakemaruMonitorThemeHooked = true;
            document.addEventListener('livewire:navigated', apply);
        }
    })();
</script>
@vite('resources/css/monitor/monitor.css')
