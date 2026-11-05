{{-- 酒丸守人 ログイン画面のレイアウト（背景・見出し・フッター）。カード本体は monitor/auth/login.blade.php --}}
<x-filament-panels::layout.base :livewire="$livewire">
    {{-- 監視画面のダークテーマ設定を引き継がない（カードは常に白地） --}}
    <script>document.documentElement.classList.remove('dark');</script>
    @vite('resources/css/monitor/login.css')
    <link rel="preload" as="image" href="{{ asset('images/moribito/loading.gif') }}">

    <div class="mrb-login" style="--mrb-bg: url('{{ asset('images/moribito/login-bg.jpg') }}')">
        <div class="mrb-shade" aria-hidden="true"></div>

        <header class="mrb-top">
            <span class="mrb-seal" aria-hidden="true">酒</span>
            <span class="mrb-top-rule" aria-hidden="true"></span>
            <span class="mrb-company">SMART LIFE LLC</span>
        </header>

        <div class="mrb-stage">
            <section class="mrb-hero">
                <p class="mrb-hero-title">酒流通のシステムを、<br>24時間見守る。</p>
                <span class="mrb-hero-rule" aria-hidden="true"></span>
                <p class="mrb-hero-sub">セキュリティと安定運用を支える<br>統合監視プラットフォーム。</p>
            </section>

            <main class="mrb-main">
                {{ $slot }}
            </main>
        </div>

        <footer class="mrb-bottom">
            <span>&copy; {{ date('Y') }} Smart Life LLC.</span>
            @if (filled(config('app.version')))
                <span>SYSTEM v{{ config('app.version') }}</span>
            @endif
        </footer>
    </div>

    {{-- ログイン成功（リダイレクト）時は、画面が切り替わるまで読み込み表示を残す --}}
    <script data-navigate-once>
        document.addEventListener('livewire:init', () => {
            Livewire.hook('commit', ({ succeed }) => {
                succeed(({ effects }) => {
                    if (effects && effects.redirect) {
                        document.documentElement.classList.add('mrb-leaving');
                    }
                });
            });
        });
    </script>
</x-filament-panels::layout.base>
