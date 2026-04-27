@props([
    'position' => null,
])

@php
    use Filament\Enums\UserMenuPosition;
    use Filament\Support\Facades\FilamentView;
    use Filament\View\PanelsRenderHook;
    use Illuminate\Contracts\Support\Htmlable;

    $user = filament()->auth()->user();

    $items = filament()->getUserMenuItems();

    $profileItem = $items['profile'] ?? $items['account'] ?? null;
    $profileUrl = $profileItem?->getUrl() ?? (filament()->hasProfile() ? filament()->getProfileUrl() : null);
    $profileTarget = ($profileItem?->shouldOpenUrlInNewTab() ?? false) ? '_blank' : null;
    $profileLabel = $profileItem?->getLabel() ?? 'アカウント設定';
    $isProfileVisible = filled($profileUrl) && ($profileItem?->isVisible() ?? true);

    $logoutItem = $items['logout'] ?? null;
    $logoutUrl = $logoutItem?->getUrl() ?? filament()->getLogoutUrl();
    $logoutLabel = $logoutItem?->getLabel() ?? 'ログアウト';

    $homeUrl = filament()->getHomeUrl();

    $brandName = trim(strip_tags((string) filament()->getBrandName()));
    $brandLogo = filament()->getBrandLogo();
    $brandLogoUrl = ($brandLogo instanceof Htmlable) ? null : (filled($brandLogo) ? (string) $brandLogo : null);

    if (! $brandLogoUrl) {
        foreach (['images/logo.png', 'images/logo-big.png', 'images/simple-logo.png', 'images/hana-logo.png', 'favicon.ico'] as $logoCandidate) {
            if (is_file(public_path($logoCandidate))) {
                $brandLogoUrl = asset($logoCandidate);

                break;
            }
        }
    }

    $userName = filament()->getUserName($user);
    $userEmail = $user?->email ?? $user?->mail_address ?? $user?->login_id ?? null;
    $serverId = (string) config('app.server_id', 'local');

    $version = 'dev';
    $versionPath = base_path('VERSION.md');

    if (is_file($versionPath) && is_readable($versionPath)) {
        $rawVersion = trim((string) file_get_contents($versionPath));

        if ($rawVersion !== '') {
            if (preg_match('/v?(\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?)/', $rawVersion, $matches) === 1) {
                $version = $matches[1];
            } else {
                foreach (preg_split('/\R/', $rawVersion) ?: [] as $line) {
                    $line = trim(str_replace(['#', '*', '`'], '', $line));

                    if ($line === '') {
                        continue;
                    }

                    $version = $line;

                    break;
                }
            }
        }
    }

    $position ??= filament()->getUserMenuPosition();
    $isSidebarCollapsibleOnDesktop = filament()->isSidebarCollapsibleOnDesktop();
@endphp

{{ FilamentView::renderHook(PanelsRenderHook::USER_MENU_BEFORE) }}

<div
    x-data="{ isOpen: false }"
    class="fi-user-menu"
>
    <button
        aria-label="{{ __('filament-panels::layout.actions.open_user_menu.label') }}"
        type="button"
        class="fi-user-menu-trigger"
        @click="isOpen = true"
    >
        <x-filament-panels::avatar.user :user="$user" loading="lazy" />

        @if ($position !== UserMenuPosition::Topbar)
            <span
                @if ($isSidebarCollapsibleOnDesktop)
                    x-show="$store.sidebar.isOpen"
                @endif
                class="fi-user-menu-trigger-text"
            >
                {{ $userName }}
            </span>

            {{
                \Filament\Support\generate_icon_html(\Filament\Support\Icons\Heroicon::ChevronUp, alias: \Filament\View\PanelsIconAlias::USER_MENU_TOGGLE_BUTTON, attributes: new \Illuminate\View\ComponentAttributeBag([
                    'x-show' => $isSidebarCollapsibleOnDesktop ? '$store.sidebar.isOpen' : null,
                ]))
            }}
        @endif
    </button>

    <template x-teleport="body">
        <div
            x-cloak
            x-show="isOpen"
            x-on:keydown.escape.window="isOpen = false"
            class="fixed inset-0 z-[100] flex items-center justify-center p-4"
        >
            <div
                class="absolute inset-0 bg-slate-900/55"
                @click="isOpen = false"
            ></div>

            <div
                @click.stop
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100 scale-100"
                x-transition:leave-end="opacity-0 scale-95"
                class="relative z-10 flex w-full max-w-md flex-col overflow-hidden rounded-2xl bg-white shadow-2xl"
            >
                <div class="flex items-center justify-between border-b border-slate-200 bg-white px-5 py-4">
                    <div class="flex-1"></div>

                    <div class="flex flex-1 justify-center">
                        @if ($brandLogoUrl)
                            <img
                                src="{{ $brandLogoUrl }}"
                                alt="{{ $brandName }}"
                                class="h-10 w-auto max-w-[220px] object-contain"
                            >
                        @else
                            <span class="text-lg font-semibold text-slate-900">
                                {{ $brandName }}
                            </span>
                        @endif
                    </div>

                    <div class="flex flex-1 justify-end">
                        <button
                            type="button"
                            class="rounded-lg p-2 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"
                            @click="isOpen = false"
                        >
                            <span class="sr-only">閉じる</span>
                            <x-filament::icon
                                icon="heroicon-o-x-mark"
                                class="h-5 w-5"
                            />
                        </button>
                    </div>
                </div>

                <div class="border-b border-slate-200 bg-slate-50 px-5 py-4">
                    <div class="flex items-center gap-3">
                        <x-filament-panels::avatar.user
                            :user="$user"
                            size="lg"
                            loading="lazy"
                        />

                        <div class="min-w-0">
                            <p class="truncate text-base font-semibold text-slate-900">
                                {{ $userName }}
                            </p>

                            @if (filled($userEmail))
                                <p class="truncate text-sm text-slate-500">
                                    {{ $userEmail }}
                                </p>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="flex flex-col gap-5 px-5 py-5">
                    @if ($isProfileVisible || filled($homeUrl))
                        <div class="space-y-2">
                            <p class="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">
                                アクション
                            </p>

                            <div class="space-y-2">
                                @if ($isProfileVisible)
                                    {{ FilamentView::renderHook(PanelsRenderHook::USER_MENU_PROFILE_BEFORE) }}

                                    <a
                                        href="{{ $profileUrl }}"
                                        @if ($profileTarget)
                                            target="{{ $profileTarget }}"
                                            rel="noopener noreferrer"
                                        @endif
                                        class="flex items-center justify-between rounded-xl border border-slate-200 bg-white px-4 py-3 transition hover:border-slate-300 hover:bg-slate-50"
                                    >
                                        <div class="flex items-center gap-3">
                                            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-slate-100 text-slate-500">
                                                <x-filament::icon
                                                    icon="heroicon-o-user-circle"
                                                    class="h-5 w-5"
                                                />
                                            </span>

                                            <div>
                                                <p class="text-sm font-semibold text-slate-900">
                                                    {{ $profileLabel }}
                                                </p>
                                                <p class="text-xs text-slate-500">
                                                    プロフィール・パスワード変更
                                                </p>
                                            </div>
                                        </div>

                                        <x-filament::icon
                                            icon="heroicon-o-chevron-right"
                                            class="h-4 w-4 text-slate-400"
                                        />
                                    </a>

                                    {{ FilamentView::renderHook(PanelsRenderHook::USER_MENU_PROFILE_AFTER) }}
                                @endif

                                @if (filled($homeUrl))
                                    <a
                                        href="{{ $homeUrl }}"
                                        class="flex items-center justify-between rounded-xl border border-slate-200 bg-white px-4 py-3 transition hover:border-slate-300 hover:bg-slate-50"
                                    >
                                        <div class="flex items-center gap-3">
                                            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-slate-100 text-slate-500">
                                                <x-filament::icon
                                                    icon="heroicon-o-home"
                                                    class="h-5 w-5"
                                                />
                                            </span>

                                            <div>
                                                <p class="text-sm font-semibold text-slate-900">
                                                    ホーム
                                                </p>
                                                <p class="text-xs text-slate-500">
                                                    ホーム画面に戻る
                                                </p>
                                            </div>
                                        </div>

                                        <x-filament::icon
                                            icon="heroicon-o-chevron-right"
                                            class="h-4 w-4 text-slate-400"
                                        />
                                    </a>
                                @endif
                            </div>
                        </div>
                    @endif

                    <div class="space-y-2">
                        <p class="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">
                            システム情報
                        </p>

                        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-slate-50">
                            <dl class="divide-y divide-slate-200">
                                <div class="flex items-start justify-between gap-4 px-4 py-3">
                                    <dt class="text-sm font-medium text-slate-500">
                                        システム環境
                                    </dt>
                                    <dd class="font-mono text-sm font-semibold text-slate-900">
                                        {{ $serverId }}
                                    </dd>
                                </div>

                                <div class="flex items-start justify-between gap-4 px-4 py-3">
                                    <dt class="text-sm font-medium text-slate-500">
                                        システムバージョン
                                    </dt>
                                    <dd class="font-mono text-sm font-semibold text-slate-900">
                                        {{ $version }}
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-5 py-4">
                    <span class="text-xs text-slate-400">ESC で閉じる</span>

                    <div class="flex items-center justify-end gap-3">
                        <button
                            type="button"
                            class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-100"
                            @click="isOpen = false"
                        >
                            閉じる
                        </button>

                        <form
                            action="{{ $logoutUrl }}"
                            method="post"
                        >
                            @csrf

                            <button
                                type="submit"
                                class="inline-flex items-center rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-red-700"
                            >
                                {{ $logoutLabel }}
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>

{{ FilamentView::renderHook(PanelsRenderHook::USER_MENU_AFTER) }}
