<x-filament-panels::page>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>

    @php
        $kpi = $this->getKpiData();
        $areaChart = $this->getAreaChartData();
        $storeBar = $this->getStoreBarChartData();
    @endphp

    <div x-data="areaDailyReport()" x-init="init()" class="ins-page space-y-5" style="position: relative; z-index: 1;">
        {{-- Header with date filter --}}
        <div class="flex items-center justify-between">
            <p class="text-xs" style="color: var(--sm-text-muted); font-family: var(--sm-font-body); letter-spacing: 0.02em;">
                全店舗の日次販売パフォーマンスを一目で把握
            </p>
            <div class="flex items-center gap-3">
                <input
                    type="date"
                    wire:model.live="filterDate"
                    class="ins-date-input"
                />
                <button onclick="window.print()" class="ins-btn">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                    印刷
                </button>
            </div>
        </div>

        {{-- KPI Cards --}}
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="ins-card ins-kpi ins-kpi--sales">
                <div class="ins-kpi__label">当日売上高</div>
                <div class="ins-kpi__value">&yen;{{ number_format($kpi['total_sales']) }}</div>
            </div>
            <div class="ins-card ins-kpi ins-kpi--customers">
                <div class="ins-kpi__label">当日客数</div>
                <div class="ins-kpi__value">{{ number_format($kpi['total_customers']) }}<span style="font-size: 0.9rem; font-weight: 400; color: var(--sm-text-muted); margin-left: 2px;">人</span></div>
            </div>
            <div class="ins-card ins-kpi ins-kpi--price">
                <div class="ins-kpi__label">客単価</div>
                <div class="ins-kpi__value">&yen;{{ number_format($kpi['avg_unit_price']) }}</div>
            </div>
            <div class="ins-card ins-kpi ins-kpi--profit">
                <div class="ins-kpi__label">粗利益</div>
                <div class="ins-kpi__value">&yen;{{ number_format($kpi['total_profit']) }}</div>
                <div class="ins-kpi__sub" style="color: var(--sm-accent-gold);">粗利率 {{ $kpi['profit_rate'] }}%</div>
            </div>
        </div>

        {{-- Charts Section --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
            <div class="ins-chart-wrap">
                <h3 class="ins-chart-title">エリア別売上構成</h3>
                <div class="flex items-center justify-center" style="height: 280px;">
                    <canvas id="areaDonutChart"></canvas>
                </div>
            </div>
            <div class="ins-chart-wrap">
                <h3 class="ins-chart-title">店舗別売上比較（上位8店舗）</h3>
                <div style="height: 280px;">
                    <canvas id="storeBarChart"></canvas>
                </div>
            </div>
        </div>

        {{-- Store Sales Map --}}
        <div class="ins-card">
            <div class="ins-section-header">
                <span class="ins-section-title">店舗別売上マップ（POS実店舗）</span>
            </div>
            <div class="p-2">
                <livewire:store-sales-map :filter-date="$this->filterDate" />
            </div>
        </div>

        {{-- Store Table --}}
        <div class="ins-card">
            <div class="ins-section-header">
                <span class="ins-section-title">店舗別詳細</span>
            </div>
            <div class="p-2">
                {{ $this->table }}
            </div>
        </div>
    </div>

    <script>
        function areaDailyReport() {
            return {
                donutChart: null,
                barChart: null,
                init() {
                    this.$nextTick(() => {
                        this.renderDonut();
                        this.renderBar();
                    });
                },
                renderDonut() {
                    const ctx = document.getElementById('areaDonutChart');
                    if (!ctx) return;
                    if (this.donutChart) this.donutChart.destroy();

                    const data = @json($areaChart);
                    const warmColors = ['#8b3a2f', '#b8860b', '#2c4a6e', '#6b5b8a', '#2d6a4f', '#c47a3b', '#5a7a94', '#9c6b5e'];
                    this.donutChart = new Chart(ctx, {
                        type: 'doughnut',
                        data: {
                            labels: data.labels,
                            datasets: [{
                                data: data.values,
                                backgroundColor: warmColors.slice(0, data.labels.length),
                                borderWidth: 2,
                                borderColor: '#fefcf6',
                                hoverBorderWidth: 0,
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            cutout: '55%',
                            plugins: {
                                legend: {
                                    position: 'right',
                                    labels: {
                                        font: { family: "'Zen Kaku Gothic New', sans-serif", size: 11 },
                                        color: '#3f3930',
                                        padding: 12,
                                        usePointStyle: true,
                                        pointStyleWidth: 10,
                                    }
                                },
                                tooltip: {
                                    backgroundColor: '#1c1917',
                                    titleFont: { family: "'Zen Kaku Gothic New', sans-serif" },
                                    bodyFont: { family: "'Zen Kaku Gothic New', sans-serif" },
                                    cornerRadius: 8,
                                    padding: 10,
                                    callbacks: {
                                        label: function(ctx) {
                                            return ctx.label + ': \u00a5' + ctx.raw.toLocaleString('ja-JP');
                                        }
                                    }
                                }
                            }
                        }
                    });
                },
                renderBar() {
                    const ctx = document.getElementById('storeBarChart');
                    if (!ctx) return;
                    if (this.barChart) this.barChart.destroy();

                    const data = @json($storeBar);
                    this.barChart = new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: data.labels,
                            datasets: [{
                                label: '売上',
                                data: data.values,
                                backgroundColor: 'rgba(44, 74, 110, 0.75)',
                                hoverBackgroundColor: '#2c4a6e',
                                borderRadius: 4,
                                borderSkipped: false,
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            indexAxis: 'y',
                            plugins: {
                                legend: { display: false },
                                tooltip: {
                                    backgroundColor: '#1c1917',
                                    titleFont: { family: "'Zen Kaku Gothic New', sans-serif" },
                                    bodyFont: { family: "'Zen Kaku Gothic New', sans-serif" },
                                    cornerRadius: 8,
                                    padding: 10,
                                    callbacks: {
                                        label: function(ctx) {
                                            return '\u00a5' + ctx.raw.toLocaleString('ja-JP');
                                        }
                                    }
                                }
                            },
                            scales: {
                                x: {
                                    grid: { color: 'rgba(166, 152, 128, 0.1)' },
                                    ticks: {
                                        font: { family: "'Zen Kaku Gothic New', sans-serif", size: 10 },
                                        color: '#9c9487',
                                        callback: function(val) {
                                            return '\u00a5' + (val / 10000).toFixed(0) + '万';
                                        }
                                    }
                                },
                                y: {
                                    grid: { display: false },
                                    ticks: {
                                        font: { family: "'Zen Kaku Gothic New', sans-serif", size: 11 },
                                        color: '#3f3930',
                                    }
                                }
                            }
                        }
                    });
                }
            };
        }
    </script>
</x-filament-panels::page>
