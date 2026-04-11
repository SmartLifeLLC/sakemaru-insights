<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MonthlyReportResource\Pages;
use App\Models\Insights\MonthlyStoreSales;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class MonthlyReportResource extends Resource
{
    protected static ?string $model = MonthlyStoreSales::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = '月次売上レポート';

    protected static ?string $modelLabel = '月次売上レポート';

    protected static ?string $pluralModelLabel = '月次売上レポート';

    protected static string | \UnitEnum | null $navigationGroup = '統計分析';

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'monthly-report';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('year_month')
                    ->label('年月')
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
                    ->label('売上')
                    ->money('JPY')
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('sales_qty')
                    ->label('数量')
                    ->numeric()
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
                SelectFilter::make('year_month')
                    ->label('年月')
                    ->options(fn () => MonthlyStoreSales::distinct()
                        ->orderByDesc('year_month')
                        ->pluck('year_month', 'year_month')
                        ->toArray()
                    ),
                SelectFilter::make('area')
                    ->label('エリア')
                    ->options(fn () => MonthlyStoreSales::whereNotNull('area')
                        ->distinct()
                        ->pluck('area', 'area')
                        ->toArray()
                    ),
                SelectFilter::make('store_name')
                    ->label('店舗')
                    ->options(fn () => MonthlyStoreSales::distinct()
                        ->pluck('store_name', 'store_name')
                        ->toArray()
                    )
                    ->searchable(),
            ])
            ->defaultSort('year_month', 'desc')
            ->striped()
            ->defaultPaginationPageOption(100)
            ->paginationPageOptions([100, 500, 1000, 1500, 2000]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMonthlyReports::route('/'),
        ];
    }
}
