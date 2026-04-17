<div x-data="{ openTab: null }" class="relative" @click.outside="openTab = null">
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />

    <script>
        function dateBadge() {
            return {
                dateStr: @js($systemDateDisplay),
                dayStr: @js($systemDayOfWeek),
            }
        }
    </script>

    <!-- Main Navigation Bar -->
    <header class="bg-slate-800 sticky top-0 z-[35] shadow-md" style="font-family: ui-sans-serif, system-ui, sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji';">
        <div class="w-full px-4 md:px-6">
            <div class="flex items-center justify-between h-10">

                <!-- Logo & System Date -->
                <div class="flex items-center gap-2 flex-shrink-0">
                    <a href="/admin" class="hidden sm:flex items-center bg-white rounded-md px-2 py-1">
                        <img src="/images/logo.png" alt="酒丸算盤" class="h-6 w-auto object-contain">
                    </a>
                    <div x-data="dateBadge()" class="flex items-center gap-1.5 bg-slate-900/60 border border-slate-500/40 rounded-lg px-2.5 py-1 text-sm select-none cursor-default">
                        <i class="fa-regular fa-calendar-days text-amber-400"></i>
                        <span class="font-bold text-white tracking-wide" x-text="dateStr"></span>
                        <span class="text-amber-300/80 font-semibold text-xs" x-text="dayStr"></span>
                    </div>
                </div>

                <!-- Nav Items -->
                <nav class="flex-1 flex items-center justify-start gap-1">
                    @forelse($menuStructure as $tab)
                        <button
                            type="button"
                            class="flex items-center gap-1.5 px-3 py-1 text-base font-medium transition-colors duration-200 rounded-md"
                                :class="openTab === '{{ $tab['id'] }}' ? 'text-white bg-slate-700' : 'text-slate-200 hover:text-white hover:bg-slate-700'"
                                @click="openTab = openTab === '{{ $tab['id'] }}' ? null : '{{ $tab['id'] }}'"
                            >
                                <i class="fa-solid {{ $tab['icon'] }}"></i>
                            <span class="hidden lg:inline">{{ $tab['label'] }}</span>
                            <i class="fa-solid fa-chevron-down text-[10px] transition-transform duration-200"
                                   :class="openTab === '{{ $tab['id'] }}' ? 'rotate-180' : ''"></i>
                            </button>
                        @empty
                            <span class="text-red-500 text-sm">メニューが空です</span>
                        @endforelse
                </nav>

                <!-- Right: User Menu -->
                <div class="flex items-center gap-3">
                    @if (filament()->isGlobalSearchEnabled())
                        <div class="mr-2">
                            @livewire(filament()->getGlobalSearchLivewireComponent())
                        </div>
                    @endif

                    @if (filament()->hasDatabaseNotifications())
                        @livewire(filament()->getCurrentPanel()->getDatabaseNotificationsLivewireComponent())
                    @endif

                    <x-filament-panels::user-menu />
                </div>
            </div>
        </div>

        <!-- Mega Menu Dropdowns -->
        @foreach($menuStructure as $tab)
            <div
                x-show="openTab === '{{ $tab['id'] }}'"
                class="fixed left-0 right-0 bg-white border-b border-slate-200 shadow-xl z-40"
                style="top: 40px; font-family: ui-sans-serif, system-ui, sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji';"
                x-cloak
            >
                <div class="w-full px-4 py-3 overflow-x-auto">
                    <div class="flex gap-6 justify-start">
                        @foreach($tab['groups'] as $index => $group)
                            @php
                                $itemCount = count($group['items']);
                                $columns = match(true) {
                                    $itemCount > 10 => 3,
                                    $itemCount >= 5 => 2,
                                    default => 1,
                                };
                            @endphp
                            <div class="flex gap-6 flex-shrink-0">
                                @if($index > 0)
                                    <div class="w-px bg-slate-200 self-stretch"></div>
                                @endif
                                <div>
                                <div class="flex flex-col gap-1">
                                    <!-- Group Header -->
                                    <div class="flex items-center gap-1.5 pb-1.5 border-b border-slate-100">
                                        @if(isset($group['icon']) && $group['icon'])
                                            <x-filament::icon
                                                :icon="$group['icon']"
                                                class="w-4 h-4 text-indigo-600 hidden xl:block"
                                            />
                                        @endif
                                        <h3 class="font-bold text-slate-800 text-xs">{{ $group['label'] }}</h3>
                                    </div>

                                    <!-- Menu Items -->
                                    <ul class="@if($columns === 3) grid grid-cols-3 gap-0.5 @elseif($columns === 2) grid grid-cols-2 gap-0.5 @else flex flex-col gap-0.5 @endif">
                                        @foreach($group['items'] as $item)
                                            <li>
                                                <a href="{{ $item['url'] }}"
                                                   @if(!empty($item['openInSplitView']))
                                                       @click.prevent="$store.splitView.open('{{ $item['url'] }}', '{{ $item['label'] }}', '{{ $group['label'] }}'); openTab = null"
                                                   @endif
                                                   class="group flex items-center gap-1.5 px-1.5 py-0.5 rounded transition-all duration-150 hover:bg-indigo-100 {{ $item['isActive'] ? 'bg-indigo-50' : '' }}">
                                                    <div class="flex flex-shrink-0 p-1 rounded bg-white border border-slate-200 text-slate-500 transition-colors shadow-sm group-hover:bg-indigo-600 group-hover:border-indigo-600 group-hover:text-white {{ $item['isActive'] ? 'text-indigo-600 border-indigo-200' : '' }}">
                                                        @if(!empty($item['openInSplitView']))
                                                            <i class="fa-solid fa-table-columns w-3.5 h-3.5 flex items-center justify-center text-[10px]"></i>
                                                        @elseif(isset($item['icon']) && $item['icon'])
                                                            <x-filament::icon
                                                                :icon="$item['icon']"
                                                                class="w-3.5 h-3.5"
                                                            />
                                                        @else
                                                            <i class="fa-solid fa-circle text-[5px] w-3.5 h-3.5 flex items-center justify-center"></i>
                                                        @endif
                                                    </div>
                                                    <div class="flex flex-col">
                                                        <span class="text-xs font-medium text-slate-700 whitespace-nowrap transition-colors group-hover:text-indigo-700 group-hover:font-semibold {{ $item['isActive'] ? 'text-indigo-700 font-semibold' : '' }}">
                                                            {{ $item['label'] }}
                                                        </span>
                                                        @if(!empty($item['desc']))
                                                            <span class="text-[10px] text-slate-400">{{ $item['desc'] }}</span>
                                                        @endif
                                                    </div>
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

            </div>
        @endforeach
    </header>
</div>
