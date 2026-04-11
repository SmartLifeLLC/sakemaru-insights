<?php

namespace App\Filament\Pages;

use App\Models\Insights\DailyStoreSales;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AreaDailySalesReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'エリア別売上日報';

    protected static ?string $title = 'エリア別売上日報';

    protected static ?string $slug = 'area-daily-sales';

    protected static string | \UnitEnum | null $navigationGroup = '統計分析';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.area-daily-sales-report';

    public ?string $filterDate = null;

    public function mount(): void
    {
        $this->filterDate = $this->getLatestDate();
    }

    protected function getLatestDate(): string
    {
        $latest = DailyStoreSales::max('business_date');

        return $latest ? \Carbon\Carbon::parse($latest)->format('Y-m-d') : now()->format('Y-m-d');
    }

    public function updatedFilterDate(): void
    {
        // Livewire reactive update
    }

    public function getKpiData(): array
    {
        $date = $this->filterDate;

        $data = DailyStoreSales::where('business_date', $date)
            ->selectRaw('SUM(sales_amount) as total_sales')
            ->selectRaw('SUM(customer_count) as total_customers')
            ->selectRaw('CASE WHEN SUM(customer_count) > 0 THEN ROUND(SUM(sales_amount) / SUM(customer_count)) ELSE 0 END as avg_unit_price')
            ->selectRaw('SUM(gross_profit) as total_profit')
            ->selectRaw('CASE WHEN SUM(sales_amount) > 0 THEN ROUND(SUM(gross_profit) / SUM(sales_amount) * 100, 1) ELSE 0 END as profit_rate')
            ->first();

        return [
            'total_sales' => (int) ($data->total_sales ?? 0),
            'total_customers' => (int) ($data->total_customers ?? 0),
            'avg_unit_price' => (int) ($data->avg_unit_price ?? 0),
            'total_profit' => (int) ($data->total_profit ?? 0),
            'profit_rate' => (float) ($data->profit_rate ?? 0),
        ];
    }

    public function getAreaChartData(): array
    {
        $date = $this->filterDate;

        $areas = DailyStoreSales::where('business_date', $date)
            ->selectRaw("COALESCE(area, 'その他') as area_name, SUM(sales_amount) as total")
            ->groupBy('area_name')
            ->orderByDesc('total')
            ->get();

        $colors = ['#4f46e5', '#6366f1', '#818cf8', '#a5b4fc', '#c7d2fe'];

        $labels = [];
        $values = [];
        $bgColors = [];

        foreach ($areas as $i => $area) {
            $labels[] = $area->area_name;
            $values[] = (int) $area->total;
            $bgColors[] = $colors[$i % count($colors)];
        }

        return [
            'labels' => $labels,
            'values' => $values,
            'colors' => $bgColors,
        ];
    }

    public function getStoreBarChartData(): array
    {
        $date = $this->filterDate;

        $stores = DailyStoreSales::where('business_date', $date)
            ->orderByDesc('sales_amount')
            ->limit(8)
            ->get(['store_name', 'sales_amount']);

        return [
            'labels' => $stores->pluck('store_name')->toArray(),
            'values' => $stores->pluck('sales_amount')->map(fn ($v) => (int) $v)->toArray(),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                DailyStoreSales::query()
                    ->when($this->filterDate, fn (Builder $q) => $q->where('business_date', $this->filterDate))
            )
            ->columns([
                TextColumn::make('store_name')
                    ->label('店舗名')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('area')
                    ->label('エリア')
                    ->sortable()
                    ->default('--'),
                TextColumn::make('customer_count')
                    ->label('客数')
                    ->sortable()
                    ->numeric()
                    ->alignEnd(),
                TextColumn::make('unit_price')
                    ->label('客単価')
                    ->sortable()
                    ->money('JPY')
                    ->alignEnd(),
                TextColumn::make('sales_amount')
                    ->label('売上')
                    ->sortable()
                    ->money('JPY')
                    ->alignEnd(),
                TextColumn::make('gross_profit')
                    ->label('粗利益')
                    ->sortable()
                    ->money('JPY')
                    ->alignEnd(),
                TextColumn::make('gross_profit_rate')
                    ->label('粗利率')
                    ->sortable()
                    ->suffix('%')
                    ->alignEnd()
                    ->color(fn ($state) => match (true) {
                        $state >= 30 => 'success',
                        $state >= 20 => 'warning',
                        default => 'danger',
                    }),
            ])
            ->defaultSort('sales_amount', 'desc')
            ->striped()
            ->paginated(false);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
