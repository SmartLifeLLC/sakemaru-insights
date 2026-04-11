<x-filament-panels::page>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>

    @php
        $trendData = $this->getMonthlyTrendData();
        $topItems = $this->getTopItemsByMonth();
    @endphp

    <div x-data="monthlyReport()" x-init="init()" class="ins-page space-y-5" style="position: relative; z-index: 1;">
        {{-- Monthly Trend Chart --}}
        @if(count($trendData['labels']) > 0)
        <div class="ins-chart-wrap">
            <h3 class="ins-chart-title">月次売上トレンド</h3>
            <div style="height: 300px;">
                <canvas id="monthlyTrendChart"></canvas>
            </div>
        </div>
        @endif

        {{-- Store Monthly Table --}}
        <div class="ins-card">
            <div class="ins-section-header">
                <span class="ins-section-title">店舗別月次サマリ</span>
            </div>
            <div class="p-2">
                {{ $this->table }}
            </div>
        </div>

        {{-- Top Items Table --}}
        @if(count($topItems) > 0)
        <div class="ins-card">
            <div class="ins-section-header">
                <span class="ins-section-title">商品別月次売上（上位20商品）</span>
            </div>
            <div class="p-4">
                <table class="ins-table">
                    <thead>
                        <tr>
                            <th class="text-left" style="width: 2.5rem;">#</th>
                            <th class="text-left">商品名</th>
                            <th class="text-left">カテゴリ</th>
                            <th class="text-right">売上</th>
                            <th class="text-right">数量</th>
                            <th class="text-right">粗利益</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($topItems as $index => $item)
                        <tr>
                            <td style="color: var(--sm-text-muted); font-weight: 600; font-size: 0.7rem;">{{ $index + 1 }}</td>
                            <td style="color: var(--sm-text-heading); font-weight: 500;">{{ $item['item_name'] ?? '--' }}</td>
                            <td>
                                <span class="ins-badge">{{ $item['category_name'] ?? '--' }}</span>
                            </td>
                            <td class="text-right font-medium" style="color: var(--sm-text-heading);">&yen;{{ number_format($item['sales_amount'] ?? 0) }}</td>
                            <td class="text-right">{{ number_format($item['sales_qty'] ?? 0) }}</td>
                            <td class="text-right" style="color: var(--sm-accent-gold); font-weight: 500;">&yen;{{ number_format($item['gross_profit'] ?? 0) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif
    </div>

    <script>
        function monthlyReport() {
            return {
                chart: null,
                init() {
                    this.$nextTick(() => {
                        const ctx = document.getElementById('monthlyTrendChart');
                        if (!ctx) return;

                        const data = @json($trendData);
                        this.chart = new Chart(ctx, {
                            type: 'line',
                            data: {
                                labels: data.labels,
                                datasets: [
                                    {
                                        label: '売上',
                                        data: data.sales,
                                        borderColor: '#2c4a6e',
                                        backgroundColor: 'rgba(44, 74, 110, 0.06)',
                                        fill: true,
                                        tension: 0.35,
                                        pointBackgroundColor: '#2c4a6e',
                                        pointBorderColor: '#fefcf6',
                                        pointBorderWidth: 2,
                                        pointRadius: 5,
                                        pointHoverRadius: 7,
                                        borderWidth: 2.5,
                                        yAxisID: 'y',
                                    },
                                    {
                                        label: '粗利益',
                                        data: data.profit,
                                        borderColor: '#b8860b',
                                        backgroundColor: 'rgba(184, 134, 11, 0.06)',
                                        fill: true,
                                        tension: 0.35,
                                        pointBackgroundColor: '#b8860b',
                                        pointBorderColor: '#fefcf6',
                                        pointBorderWidth: 2,
                                        pointRadius: 4,
                                        pointHoverRadius: 6,
                                        borderWidth: 2,
                                        yAxisID: 'y',
                                    }
                                ]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                interaction: { mode: 'index', intersect: false },
                                plugins: {
                                    legend: {
                                        labels: {
                                            font: { family: "'Zen Kaku Gothic New', sans-serif", size: 11 },
                                            color: '#3f3930',
                                            usePointStyle: true,
                                            padding: 16,
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
                                                return ctx.dataset.label + ': \u00a5' + ctx.raw.toLocaleString('ja-JP');
                                            }
                                        }
                                    }
                                },
                                scales: {
                                    x: {
                                        grid: { color: 'rgba(166, 152, 128, 0.08)' },
                                        ticks: {
                                            font: { family: "'Zen Kaku Gothic New', sans-serif", size: 10 },
                                            color: '#9c9487',
                                        }
                                    },
                                    y: {
                                        grid: { color: 'rgba(166, 152, 128, 0.1)' },
                                        ticks: {
                                            font: { family: "'Zen Kaku Gothic New', sans-serif", size: 10 },
                                            color: '#9c9487',
                                            callback: function(val) {
                                                return '\u00a5' + (val / 10000).toFixed(0) + '万';
                                            }
                                        }
                                    }
                                }
                            }
                        });
                    });
                }
            };
        }
    </script>
</x-filament-panels::page>
