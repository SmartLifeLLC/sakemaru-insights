<?php

namespace App\Filament\Resources;

use App\Filament\Resources\HourlyStoreSalesResource\Pages;
use App\Models\Insights\HourlyStoreSales;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class HourlyStoreSalesResource extends Resource
{
    protected static ?string $model = HourlyStoreSales::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationLabel = '時間帯別売上';

    protected static ?string $modelLabel = '時間帯別売上';

    protected static ?string $pluralModelLabel = '時間帯別売上';

    protected static string | \UnitEnum | null $navigationGroup = '統計分析';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'hourly-sales';

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
                TextColumn::make('time_slot')
                    ->label('時間帯')
                    ->sortable()
                    ->badge()
                    ->color('info'),
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
                TextColumn::make('gross_profit')
                    ->label('粗利益')
                    ->money('JPY')
                    ->sortable()
                    ->alignEnd(),
            ])
            ->filters([
                Filter::make('business_date')
                    ->form([
                        DatePicker::make('date')
                            ->label('日付')
                            ->default(fn () => HourlyStoreSales::max('business_date') ?? now()->format('Y-m-d')),
                    ])
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['date'],
                        fn (Builder $q, $date) => $q->where('business_date', $date)
                    ))
                    ->indicateUsing(fn (array $data) => $data['date'] ? '日付: ' . $data['date'] : null),
                SelectFilter::make('store_name')
                    ->label('店舗')
                    ->options(fn () => HourlyStoreSales::distinct()
                        ->pluck('store_name', 'store_name')
                        ->toArray()
                    )
                    ->searchable(),
            ])
            ->defaultSort('time_slot', 'asc')
            ->striped()
            ->defaultPaginationPageOption(100)
            ->paginationPageOptions([100, 500, 1000, 1500, 2000]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListHourlyStoreSales::route('/'),
        ];
    }
}
