<?php

namespace App\Filament\Resources\HourlyStoreSalesResource\Pages;

use App\Filament\Resources\HourlyStoreSalesResource;
use App\Models\Insights\HourlyStoreSales;
use Filament\Resources\Pages\ListRecords;

class ListHourlyStoreSales extends ListRecords
{
    protected static string $resource = HourlyStoreSalesResource::class;

    protected string $view = 'filament.pages.hourly-store-sales-list';

    public ?string $filterDate = null;

    public function mount(): void
    {
        parent::mount();
        $this->filterDate = HourlyStoreSales::max('business_date') ?? now()->format('Y-m-d');
    }

    public function getHourlyChartData(): array
    {
        $date = $this->filterDate;

        $data = HourlyStoreSales::where('business_date', $date)
            ->selectRaw('time_slot, SUM(sales_amount) as total_sales, SUM(customer_count) as total_customers')
            ->groupBy('time_slot')
            ->orderBy('time_slot')
            ->get();

        return [
            'labels' => $data->pluck('time_slot')->toArray(),
            'sales' => $data->pluck('total_sales')->map(fn ($v) => (int) $v)->toArray(),
            'customers' => $data->pluck('total_customers')->map(fn ($v) => (int) $v)->toArray(),
        ];
    }
}
