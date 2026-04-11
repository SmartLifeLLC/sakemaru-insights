<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SalesReportResource\Pages;
use App\Models\Insights\DailyStoreSales;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SalesReportResource extends Resource
{
    protected static ?string $model = DailyStoreSales::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = '売上報告書';

    protected static ?string $modelLabel = '売上報告書';

    protected static ?string $pluralModelLabel = '売上報告書';

    protected static string | \UnitEnum | null $navigationGroup = '統計分析';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'sales-report';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('business_date')
                    ->label('日付')
                    ->date('Y/m/d')
                    ->sortable(),
                TextColumn::make('store_name')
                    ->label('店舗名')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('area')
                    ->label('エリア')
                    ->sortable()
                    ->default('--'),
                TextColumn::make('sales_amount')
                    ->label('売上高')
                    ->money('JPY')
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('customer_count')
                    ->label('客数')
                    ->numeric()
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('unit_price')
                    ->label('客単価')
                    ->money('JPY')
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('gross_profit')
                    ->label('粗利益')
                    ->money('JPY')
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('gross_profit_rate')
                    ->label('粗利率')
                    ->suffix('%')
                    ->sortable()
                    ->alignEnd()
                    ->color(fn ($state) => match (true) {
                        $state >= 30 => 'success',
                        $state >= 20 => 'warning',
                        default => 'danger',
                    }),
            ])
            ->filters([
                Filter::make('business_date')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('date')
                            ->label('日付')
                            ->default(fn () => DailyStoreSales::max('business_date') ?? now()->format('Y-m-d')),
                    ])
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['date'],
                        fn (Builder $q, $date) => $q->where('business_date', $date)
                    ))
                    ->indicateUsing(fn (array $data) => $data['date'] ? '日付: ' . $data['date'] : null),
                SelectFilter::make('area')
                    ->label('エリア')
                    ->options(fn () => DailyStoreSales::whereNotNull('area')
                        ->distinct()
                        ->pluck('area', 'area')
                        ->toArray()
                    ),
            ])
            ->defaultSort('sales_amount', 'desc')
            ->striped()
            ->defaultPaginationPageOption(100)
            ->paginationPageOptions([100, 500, 1000, 1500, 2000]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSalesReports::route('/'),
        ];
    }
}
