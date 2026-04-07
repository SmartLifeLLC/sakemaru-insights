<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>売上分析ダッシュボード - 2025/05/28</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <script>
        // Alpine.jsが初期化される前にコンポーネントを登録する
        document.addEventListener('alpine:init', () => {
            Alpine.data('salesDashboard', () => ({
                reportDate: '2025/05/28 水曜日',
                totalSales: 7486661,
                totalCustomers: 1840,
                avgSpend: 4069,
                totalMargin: 1318564,

                storeData: [
                    { name: '本店', customers: 167, avgSpend: 3912, sales: 653369, margin: 16.5 },
                    { name: '二の宮店', customers: 225, avgSpend: 4039, sales: 908731, margin: 17.4 },
                    { name: '光陽店', customers: 163, avgSpend: 3969, sales: 646917, margin: 17.0 },
                    { name: 'プラザ店', customers: 149, avgSpend: 3579, sales: 533323, margin: 19.4 },
                    { name: '江守店', customers: 144, avgSpend: 3404, sales: 490166, margin: 18.2 },
                    { name: 'サンドーム前店', customers: 215, avgSpend: 4393, sales: 944488, margin: 17.8 },
                    { name: '越前店', customers: 148, avgSpend: 4583, sales: 678280, margin: 16.5 },
                    { name: '敦賀店', customers: 202, avgSpend: 4442, sales: 897336, margin: 16.6 },
                    { name: '小浜店', customers: 101, avgSpend: 3833, sales: 387125, margin: 17.7 },
                    { name: '坂井店', customers: 202, avgSpend: 4455, sales: 899901, margin: 17.7 },
                    { name: 'ヴィオ店', customers: 93, avgSpend: 3700, sales: 344081, margin: 15.1 },
                    { name: '金沢店', customers: 31, avgSpend: 3321, sales: 102944, margin: 39.6 }
                ],

                formatNumber(num) {
                    return new Intl.NumberFormat('ja-JP').format(num);
                },

                init() {
                    // DOMの構築完了後にチャートを描画
                    this.$nextTick(() => {
                        this.initAreaChart();
                        this.initStoreChart();
                    });
                },

                initAreaChart() {
                    const canvas = document.getElementById('areaChart');
                    if (!canvas) return;
                    new Chart(canvas.getContext('2d'), {
                        type: 'doughnut',
                        data: {
                            labels: ['嶺北', '丹南', '嶺南', '坂井', 'その他'],
                            datasets: [{
                                data: [3232506, 1622768, 1284461, 899901, 447025],
                                backgroundColor: ['#4f46e5', '#06b6d4', '#f59e0b', '#10b981', '#94a3b8'],
                                borderWidth: 0,
                                hoverOffset: 15
                            }]
                        },
                        options: {
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'right',
                                    labels: { usePointStyle: true, padding: 20, font: { size: 12 } }
                                }
                            },
                            cutout: '70%'
                        }
                    });
                },

                initStoreChart() {
                    const canvas = document.getElementById('storeChart');
                    if (!canvas) return;
                    const topStores = [...this.storeData].sort((a,b) => b.sales - a.sales).slice(0, 8);
                    new Chart(canvas.getContext('2d'), {
                        type: 'bar',
                        data: {
                            labels: topStores.map(s => s.name),
                            datasets: [{
                                label: '当日売上 (¥)',
                                data: topStores.map(s => s.sales),
                                backgroundColor: '#6366f1',
                                borderRadius: 6,
                                barThickness: 20
                            }]
                        },
                        options: {
                            maintainAspectRatio: false,
                            plugins: { legend: { display: false } },
                            scales: {
                                y: { beginAtZero: true, grid: { display: false }, ticks: { font: { size: 10 } } },
                                x: { grid: { display: false }, ticks: { font: { size: 10 } } }
                            }
                        }
                    });
                }
            }));
        });
    </script>
    
    <!-- Alpine.js本体はdefer属性を付けて読み込む -->
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', 'Noto Sans JP', sans-serif; }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="bg-slate-50 text-slate-900" x-data="salesDashboard">

    <!-- ナビゲーション -->
    <nav class="bg-white border-b border-slate-200 sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16 items-center">
                <div class="flex items-center gap-2">
                    <div class="w-8 h-8 bg-indigo-600 rounded-lg flex items-center justify-center">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path></svg>
                    </div>
                    <span class="text-xl font-bold tracking-tight">SalesMetrics <span class="text-indigo-600">Pro</span></span>
                </div>
                <div class="flex items-center gap-4 text-sm font-medium text-slate-500">
                    <span x-text="reportDate"></span>
                    <div class="h-4 w-px bg-slate-300"></div>
                    <span class="bg-indigo-50 text-indigo-700 px-3 py-1 rounded-full text-xs">確定報</span>
                </div>
            </div>
        </div>
    </nav>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8" x-cloak>
        
        <!-- ヘッダーセクション -->
        <div class="mb-8 flex flex-col md:flex-row md:items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-slate-900">エリア別売上日報</h1>
                <p class="text-slate-500">全店舗の販売パフォーマンスと在庫状況の要約</p>
            </div>
            <div class="flex gap-2">
                <button class="bg-white border border-slate-200 text-slate-700 px-4 py-2 rounded-lg text-sm font-medium hover:bg-slate-50 transition">
                    CSVエクスポート
                </button>
                <button class="bg-indigo-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-indigo-700 transition">
                    レポート印刷
                </button>
            </div>
        </div>

        <!-- KPIカード -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm">
                <div class="flex items-center justify-between mb-4">
                    <div class="p-2 bg-blue-50 text-blue-600 rounded-lg">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    </div>
                    <span class="text-xs font-semibold text-emerald-600 bg-emerald-50 px-2 py-1 rounded">前年比 99.7%</span>
                </div>
                <p class="text-sm font-medium text-slate-500">当日売上高</p>
                <h3 class="text-2xl font-bold text-slate-900">¥<span x-text="formatNumber(totalSales)"></span></h3>
            </div>

            <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm">
                <div class="flex items-center justify-between mb-4">
                    <div class="p-2 bg-indigo-50 text-indigo-600 rounded-lg">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                    </div>
                    <span class="text-xs font-semibold text-slate-500 bg-slate-100 px-2 py-1 rounded">累計 67,326人</span>
                </div>
                <p class="text-sm font-medium text-slate-500">当日客数</p>
                <h3 class="text-2xl font-bold text-slate-900"><span x-text="formatNumber(totalCustomers)"></span>人</h3>
            </div>

            <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm">
                <div class="flex items-center justify-between mb-4">
                    <div class="p-2 bg-purple-50 text-purple-600 rounded-lg">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path></svg>
                    </div>
                </div>
                <p class="text-sm font-medium text-slate-500">当日客単価</p>
                <h3 class="text-2xl font-bold text-slate-900">¥<span x-text="formatNumber(avgSpend)"></span></h3>
            </div>

            <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm">
                <div class="flex items-center justify-between mb-4">
                    <div class="p-2 bg-amber-50 text-amber-600 rounded-lg">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>
                    </div>
                    <span class="text-xs font-semibold text-amber-700 bg-amber-100 px-2 py-1 rounded">粗利率 17.6%</span>
                </div>
                <p class="text-sm font-medium text-slate-500">当日粗利益</p>
                <h3 class="text-2xl font-bold text-slate-900">¥<span x-text="formatNumber(totalMargin)"></span></h3>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
            <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm">
                <h4 class="text-lg font-bold text-slate-900 mb-6">エリア別売上構成（当日）</h4>
                <div class="h-64 relative">
                    <canvas id="areaChart"></canvas>
                </div>
            </div>

            <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm">
                <h4 class="text-lg font-bold text-slate-900 mb-2">店舗別売上比較</h4>
                <p class="text-xs text-slate-500 mb-6">主要店舗の当日売上実績</p>
                <div class="h-64 relative">
                    <canvas id="storeChart"></canvas>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="p-6 border-b border-slate-200 flex justify-between items-center">
                <h4 class="text-lg font-bold text-slate-900">店舗別詳細データ</h4>
                <div class="relative w-64">
                    <input type="text" placeholder="店舗を検索..." class="w-full pl-10 pr-4 py-2 bg-slate-50 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 transition">
                    <svg class="w-4 h-4 text-slate-400 absolute left-3 top-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wider">
                            <th class="px-6 py-4 font-semibold">店舗名</th>
                            <th class="px-6 py-4 font-semibold text-right">当日客数</th>
                            <th class="px-6 py-4 font-semibold text-right">客単価</th>
                            <th class="px-6 py-4 font-semibold text-right">当日売上</th>
                            <th class="px-6 py-4 font-semibold text-right">粗利率</th>
                            <th class="px-6 py-4 font-semibold text-center">目標</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-sm">
                        <template x-for="store in storeData" :key="store.name">
                            <tr class="hover:bg-slate-50 transition">
                                <td class="px-6 py-4 font-medium text-slate-900" x-text="store.name"></td>
                                <td class="px-6 py-4 text-right text-slate-600" x-text="formatNumber(store.customers)"></td>
                                <td class="px-6 py-4 text-right text-slate-600">¥<span x-text="formatNumber(store.avgSpend)"></span></td>
                                <td class="px-6 py-4 text-right font-semibold text-slate-900">¥<span x-text="formatNumber(store.sales)"></span></td>
                                <td class="px-6 py-4 text-right">
                                    <span class="inline-block w-16 text-right" x-text="store.margin + '%'"></span>
                                    <div class="inline-block w-16 h-1.5 bg-slate-100 rounded-full ml-2 overflow-hidden">
                                        <div class="h-full bg-indigo-500" :style="'width: ' + (store.margin * 3) + '%'"></div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <span :class="store.sales > 600000 ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-50 text-slate-600'" class="px-2.5 py-1 rounded-md text-[10px] font-bold uppercase">
                                        達成
                                    </span>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

    </main>

    <footer class="max-w-7xl mx-auto px-4 py-12 text-center text-slate-400 text-xs">
        <p>&copy; 2025 SalesMetrics Pro System. 全店計データに基づき作成されました。</p>
    </footer>

</body>
</html>
