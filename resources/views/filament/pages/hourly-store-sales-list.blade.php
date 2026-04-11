<x-filament-panels::page>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>

    @php
        $chartData = $this->getHourlyChartData();
    @endphp

    <div x-data="hourlyChart()" x-init="init()" class="ins-page space-y-5" style="position: relative; z-index: 1;">
        {{-- Hourly Sales Chart --}}
        @if(count($chartData['labels']) > 0)
        <div class="ins-chart-wrap">
            <h3 class="ins-chart-title">時間帯別売上推移</h3>
            <div style="height: 300px;">
                <canvas id="hourlyBarChart"></canvas>
            </div>
        </div>
        @endif

        {{-- Table --}}
        <div class="ins-card">
            <div class="ins-section-header">
                <span class="ins-section-title">時間帯別詳細</span>
            </div>
            <div class="p-2">
                {{ $this->table }}
            </div>
        </div>
    </div>

    <script>
        function hourlyChart() {
            return {
                chart: null,
                init() {
                    this.$nextTick(() => {
                        const ctx = document.getElementById('hourlyBarChart');
                        if (!ctx) return;

                        const data = @json($chartData);
                        this.chart = new Chart(ctx, {
                            type: 'bar',
                            data: {
                                labels: data.labels,
                                datasets: [
                                    {
                                        label: '売上',
                                        data: data.sales,
                                        backgroundColor: 'rgba(44, 74, 110, 0.65)',
                                        hoverBackgroundColor: '#2c4a6e',
                                        borderRadius: 3,
                                        yAxisID: 'y',
                                    },
                                    {
                                        label: '客数',
                                        data: data.customers,
                                        type: 'line',
                                        borderColor: '#8b3a2f',
                                        backgroundColor: 'rgba(139, 58, 47, 0.08)',
                                        fill: true,
                                        tension: 0.4,
                                        pointBackgroundColor: '#8b3a2f',
                                        pointBorderColor: '#fefcf6',
                                        pointBorderWidth: 2,
                                        pointRadius: 4,
                                        pointHoverRadius: 6,
                                        borderWidth: 2.5,
                                        yAxisID: 'y1',
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
                                                if (ctx.dataset.label === '売上') {
                                                    return '売上: \u00a5' + ctx.raw.toLocaleString('ja-JP');
                                                }
                                                return '客数: ' + ctx.raw.toLocaleString('ja-JP') + '人';
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
                                        position: 'left',
                                        grid: { color: 'rgba(166, 152, 128, 0.1)' },
                                        ticks: {
                                            font: { family: "'Zen Kaku Gothic New', sans-serif", size: 10 },
                                            color: '#9c9487',
                                            callback: function(val) {
                                                return '\u00a5' + (val / 10000).toFixed(0) + '万';
                                            }
                                        }
                                    },
                                    y1: {
                                        position: 'right',
                                        grid: { drawOnChartArea: false },
                                        ticks: {
                                            font: { family: "'Zen Kaku Gothic New', sans-serif", size: 10 },
                                            color: '#8b3a2f',
                                            callback: function(val) {
                                                return val + '人';
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
