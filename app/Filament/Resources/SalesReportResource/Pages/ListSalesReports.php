<?php

namespace App\Filament\Resources\SalesReportResource\Pages;

use App\Filament\Resources\SalesReportResource;
use App\Models\Insights\DailyPaymentSummary;
use App\Models\Insights\DailyStoreSales;
use Filament\Resources\Pages\ListRecords;

class ListSalesReports extends ListRecords
{
    protected static string $resource = SalesReportResource::class;

    protected string $view = 'filament.pages.sales-report-list';

    public ?string $filterDate = null;

    public function mount(): void
    {
        parent::mount();
        $this->filterDate = DailyStoreSales::max('business_date') ?? now()->format('Y-m-d');
    }

    public function getPaymentBreakdown(): array
    {
        $date = $this->filterDate;

        return DailyPaymentSummary::where('business_date', $date)
            ->selectRaw('payment_type, payment_label, SUM(amount) as total_amount, SUM(`count`) as total_count')
            ->groupBy('payment_type', 'payment_label')
            ->orderByDesc('total_amount')
            ->get()
            ->toArray();
    }

    public function getSalesSummary(): array
    {
        $date = $this->filterDate;

        $data = DailyStoreSales::where('business_date', $date)
            ->selectRaw('SUM(sales_amount) as total_sales, SUM(customer_count) as total_customers, SUM(gross_profit) as total_profit')
            ->first();

        return [
            'total_sales' => (int) ($data->total_sales ?? 0),
            'total_customers' => (int) ($data->total_customers ?? 0),
            'total_profit' => (int) ($data->total_profit ?? 0),
        ];
    }
}
