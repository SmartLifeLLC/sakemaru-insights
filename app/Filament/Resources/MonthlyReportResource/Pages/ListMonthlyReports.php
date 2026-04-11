<?php

namespace App\Filament\Resources\MonthlyReportResource\Pages;

use App\Filament\Resources\MonthlyReportResource;
use App\Models\Insights\MonthlyItemSales;
use App\Models\Insights\MonthlyStoreSales;
use Filament\Resources\Pages\ListRecords;

class ListMonthlyReports extends ListRecords
{
    protected static string $resource = MonthlyReportResource::class;

    protected string $view = 'filament.pages.monthly-report-list';

    public ?string $filterYearMonth = null;

    public function mount(): void
    {
        parent::mount();
        $this->filterYearMonth = MonthlyStoreSales::max('year_month') ?? now()->format('Y-m');
    }

    public function getMonthlyTrendData(): array
    {
        $data = MonthlyStoreSales::selectRaw('`year_month`, SUM(sales_amount) as total_sales, SUM(gross_profit) as total_profit')
            ->groupBy('year_month')
            ->orderBy('year_month')
            ->limit(12)
            ->get();

        return [
            'labels' => $data->pluck('year_month')->toArray(),
            'sales' => $data->pluck('total_sales')->map(fn ($v) => (int) $v)->toArray(),
            'profit' => $data->pluck('total_profit')->map(fn ($v) => (int) $v)->toArray(),
        ];
    }

    public function getTopItemsByMonth(): array
    {
        $yearMonth = $this->filterYearMonth;

        return MonthlyItemSales::where('year_month', $yearMonth)
            ->orderByDesc('sales_amount')
            ->limit(20)
            ->get(['item_name', 'category_name', 'sales_amount', 'sales_qty', 'gross_profit'])
            ->toArray();
    }
}
