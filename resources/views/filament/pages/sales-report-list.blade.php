<x-filament-panels::page>
    @php
        $summary = $this->getSalesSummary();
        $payments = $this->getPaymentBreakdown();
    @endphp

    <div class="ins-page space-y-5" style="position: relative; z-index: 1;">
        {{-- Sales Summary KPI --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="ins-card ins-kpi ins-kpi--sales">
                <div class="ins-kpi__label">売上高合計</div>
                <div class="ins-kpi__value">&yen;{{ number_format($summary['total_sales']) }}</div>
            </div>
            <div class="ins-card ins-kpi ins-kpi--customers">
                <div class="ins-kpi__label">客数合計</div>
                <div class="ins-kpi__value">{{ number_format($summary['total_customers']) }}<span style="font-size: 0.9rem; font-weight: 400; color: var(--sm-text-muted); margin-left: 2px;">人</span></div>
            </div>
            <div class="ins-card ins-kpi ins-kpi--profit">
                <div class="ins-kpi__label">粗利益合計</div>
                <div class="ins-kpi__value">&yen;{{ number_format($summary['total_profit']) }}</div>
            </div>
        </div>

        {{-- Payment Breakdown --}}
        @if(count($payments) > 0)
        <div class="ins-card">
            <div class="ins-section-header">
                <span class="ins-section-title">支払方法別内訳</span>
            </div>
            <div class="p-4">
                <table class="ins-table">
                    <thead>
                        <tr>
                            <th class="text-left">支払種別</th>
                            <th class="text-left">支払ラベル</th>
                            <th class="text-right">金額</th>
                            <th class="text-right">件数</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($payments as $payment)
                        <tr>
                            <td>{{ $payment['payment_type'] }}</td>
                            <td>{{ $payment['payment_label'] }}</td>
                            <td class="text-right font-medium" style="color: var(--sm-text-heading);">&yen;{{ number_format($payment['total_amount']) }}</td>
                            <td class="text-right">{{ number_format($payment['total_count']) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif

        {{-- Store Sales Table --}}
        <div class="ins-card">
            <div class="ins-section-header">
                <span class="ins-section-title">店舗別売上</span>
            </div>
            <div class="p-2">
                {{ $this->table }}
            </div>
        </div>
    </div>
</x-filament-panels::page>
