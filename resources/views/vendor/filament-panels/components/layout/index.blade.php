@php
    use Filament\Support\Enums\Width;

    $livewire ??= null;

    $hasTopbar = filament()->hasTopbar();
    $isSidebarCollapsibleOnDesktop = filament()->isSidebarCollapsibleOnDesktop();
    $isSidebarFullyCollapsibleOnDesktop = filament()->isSidebarFullyCollapsibleOnDesktop();
    $hasTopNavigation = filament()->hasTopNavigation();
    $hasNavigation = filament()->hasNavigation();
    $renderHookScopes = $livewire?->getRenderHookScopes();
    $maxContentWidth ??= (filament()->getMaxContentWidth() ?? Width::SevenExtraLarge);

    if (is_string($maxContentWidth)) {
        $maxContentWidth = Width::tryFrom($maxContentWidth) ?? $maxContentWidth;
    }
@endphp

<x-filament-panels::layout.base
    :livewire="$livewire"
    @class([
        'fi-body-has-navigation' => $hasNavigation,
        'fi-body-has-sidebar-collapsible-on-desktop' => $isSidebarCollapsibleOnDesktop,
        'fi-body-has-sidebar-fully-collapsible-on-desktop' => $isSidebarFullyCollapsibleOnDesktop,
        'fi-body-has-topbar' => $hasTopbar,
        'fi-body-has-top-navigation' => $hasTopNavigation,
    ])
>
    @if ($hasTopbar)
        <div x-data x-show="window.self === window.top" x-cloak>
            {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::TOPBAR_BEFORE, scopes: $renderHookScopes) }}

            {{-- @livewire(filament()->getTopbarLivewireComponent()) --}}
            <livewire:mega-menu />

            {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::TOPBAR_AFTER, scopes: $renderHookScopes) }}
        </div>
    @elseif ($hasNavigation)
        <div
            @if ($isSidebarFullyCollapsibleOnDesktop)
                x-data="{}"
                x-bind:class="{ 'lg:fi-hidden': $store.sidebar.isOpen }"
            @endif
            @class([
                'fi-layout-sidebar-toggle-btn-ctn',
                'lg:fi-hidden' => ! $isSidebarFullyCollapsibleOnDesktop,
            ])
        >
            <x-filament::icon-button
                color="gray"
                :icon="\Filament\Support\Icons\Heroicon::OutlinedBars3"
                :icon-alias="\Filament\View\PanelsIconAlias::SIDEBAR_EXPAND_BUTTON"
                icon-size="lg"
                :label="__('filament-panels::layout.actions.sidebar.expand.label')"
                x-cloak
                x-data="{}"
                x-on:click="$store.sidebar.open()"
                class="fi-layout-sidebar-toggle-btn"
            />
        </div>
    @endif

    <div class="fi-layout">
        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::LAYOUT_START, scopes: $renderHookScopes) }}

        @if ($hasNavigation)
            <div
                x-cloak
                x-data="{}"
                x-on:click="$store.sidebar.close()"
                x-show="$store.sidebar.isOpen"
                x-transition.opacity.300ms
                class="fi-sidebar-close-overlay"
            ></div>

            @livewire(filament()->getSidebarLivewireComponent())
        @endif

        <div
            @if ($isSidebarCollapsibleOnDesktop)
                x-data="{}"
                x-bind:class="{
                    'fi-main-ctn-sidebar-open': $store.sidebar.isOpen,
                }"
                x-bind:style="'display: flex; opacity:1;'"
                {{-- Mimics `x-cloak`, as using `x-cloak` causes visual issues with chart widgets --}}
            @elseif ($isSidebarFullyCollapsibleOnDesktop)
                x-data="{}"
                x-bind:class="{
                    'fi-main-ctn-sidebar-open': $store.sidebar.isOpen,
                }"
                x-bind:style="'display: flex; opacity:1;'"
                {{-- Mimics `x-cloak`, as using `x-cloak` causes visual issues with chart widgets --}}
            @elseif (! ($isSidebarCollapsibleOnDesktop || $isSidebarFullyCollapsibleOnDesktop || $hasTopNavigation || (! $hasNavigation)))
                x-data="{}"
                x-bind:style="'display: flex; opacity:1;'" {{-- Mimics `x-cloak`, as using `x-cloak` causes visual issues with chart widgets --}}
            @endif
            class="fi-main-ctn"
        >
            {{-- Split View コンテナ --}}
            <div x-data class="flex flex-1 overflow-hidden"
                 @mousemove.window="if($store.splitView.dragging) {
                     const rect = $el.getBoundingClientRect();
                     $store.splitView.ratio = Math.max(20, Math.min(80,
                         ((event.clientX - rect.left) / rect.width) * 100
                     ));
                 }"
                 @mouseup.window="$store.splitView.dragging = false">

                {{-- 左パネル: 既存コンテンツ --}}
                <div x-show="!$store.splitView.isOpen || $store.splitView.ratio > 0"
                     :style="$store.splitView.isOpen ? 'width:' + $store.splitView.ratio + '%' : 'width:100%'"
                     :class="$store.splitView.dragging ? '' : 'transition-[width] duration-200'"
                     class="flex flex-col min-w-0 overflow-y-auto">

                    {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::CONTENT_BEFORE, scopes: $renderHookScopes) }}

                    <main
                        @class([
                            'fi-main',
                            ($maxContentWidth instanceof Width) ? "fi-width-{$maxContentWidth->value}" : $maxContentWidth,
                        ])
                    >
                        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::CONTENT_START, scopes: $renderHookScopes) }}

                        {{ $slot }}

                        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::CONTENT_END, scopes: $renderHookScopes) }}
                    </main>

                    {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::CONTENT_AFTER, scopes: $renderHookScopes) }}

                    {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::FOOTER, scopes: $renderHookScopes) }}

                </div>{{-- /左パネル --}}

                {{-- リサイズバー --}}
                <div x-show="$store.splitView.isOpen && $store.splitView.ratio > 0" x-cloak
                     @mousedown.prevent="$store.splitView.dragging = true"
                     class="w-2 bg-gray-300 dark:bg-gray-600 hover:bg-primary-400 dark:hover:bg-primary-500 cursor-col-resize flex-shrink-0 relative group transition-colors"
                     :class="$store.splitView.dragging && '!bg-primary-500'">
                    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 flex flex-col gap-1">
                        <div class="w-1 h-1 rounded-full bg-gray-500 dark:bg-gray-400 group-hover:bg-white"></div>
                        <div class="w-1 h-1 rounded-full bg-gray-500 dark:bg-gray-400 group-hover:bg-white"></div>
                        <div class="w-1 h-1 rounded-full bg-gray-500 dark:bg-gray-400 group-hover:bg-white"></div>
                    </div>
                </div>

                {{-- 右パネル: iframe --}}
                <div x-show="$store.splitView.isOpen" x-cloak
                     :style="'width:' + ($store.splitView.ratio === 0 ? 100 : (100 - $store.splitView.ratio)) + '%'"
                     class="flex flex-col border-l border-gray-200 dark:border-gray-700 min-w-0">

                    {{-- iframe ヘッダー --}}
                    <div class="flex items-center justify-between px-2 py-1 bg-gray-50 dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 flex-shrink-0 gap-2">
                        <div class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400 min-w-0">
                            <template x-if="$store.splitView.breadcrumb">
                                <span class="flex items-center gap-1 flex-shrink-0">
                                    <span x-text="$store.splitView.breadcrumb" class="text-gray-400"></span>
                                    <x-heroicon-o-chevron-right class="w-3 h-3 text-gray-300" />
                                </span>
                            </template>
                            <span class="truncate font-medium text-gray-700 dark:text-gray-300" x-text="$store.splitView.title"></span>
                        </div>
                        <div class="flex items-center gap-1.5 flex-shrink-0">
                            <button @click="$store.splitView.setRatio(0)"
                                    :class="$store.splitView.ratio === 0 ? 'bg-primary-100 dark:bg-primary-900/40 text-primary-700 dark:text-primary-300' : 'text-gray-500 hover:bg-gray-200 dark:hover:bg-gray-700'"
                                    class="px-2 py-1 rounded text-xs font-medium" title="0:10（全画面）">0:10</button>
                            <button @click="$store.splitView.setRatio(30)"
                                    :class="Math.round($store.splitView.ratio) === 30 ? 'bg-primary-100 dark:bg-primary-900/40 text-primary-700 dark:text-primary-300' : 'text-gray-500 hover:bg-gray-200 dark:hover:bg-gray-700'"
                                    class="px-2 py-1 rounded text-xs font-medium" title="3:7">3:7</button>
                            <button @click="$store.splitView.setRatio(50)"
                                    :class="Math.round($store.splitView.ratio) === 50 ? 'bg-primary-100 dark:bg-primary-900/40 text-primary-700 dark:text-primary-300' : 'text-gray-500 hover:bg-gray-200 dark:hover:bg-gray-700'"
                                    class="px-2 py-1 rounded text-xs font-medium" title="5:5">5:5</button>
                            <button @click="$store.splitView.setRatio(70)"
                                    :class="Math.round($store.splitView.ratio) === 70 ? 'bg-primary-100 dark:bg-primary-900/40 text-primary-700 dark:text-primary-300' : 'text-gray-500 hover:bg-gray-200 dark:hover:bg-gray-700'"
                                    class="px-2 py-1 rounded text-xs font-medium" title="7:3">7:3</button>

                            <span class="w-px h-5 bg-gray-300 dark:bg-gray-600"></span>

                            <a :href="$store.splitView.url" target="_blank"
                               class="p-1.5 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 rounded hover:bg-gray-200 dark:hover:bg-gray-700"
                               title="新しいタブで開く">
                                <x-heroicon-o-arrow-top-right-on-square class="w-4 h-4" />
                            </a>
                            <button @click="$store.splitView.close()"
                                    class="p-1.5 text-gray-400 hover:text-red-500 rounded hover:bg-gray-200 dark:hover:bg-gray-700"
                                    title="閉じる">
                                <x-heroicon-o-x-mark class="w-4 h-4" />
                            </button>
                        </div>
                    </div>

                    {{-- iframe 本体 --}}
                    <iframe :src="$store.splitView.url"
                            class="flex-1 w-full border-0"
                            :class="$store.splitView.dragging && 'pointer-events-none'"
                            x-show="$store.splitView.url"></iframe>
                </div>

            </div>{{-- /Split View コンテナ --}}
        </div>

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::LAYOUT_END, scopes: $renderHookScopes) }}
    </div>
</x-filament-panels::layout.base>
