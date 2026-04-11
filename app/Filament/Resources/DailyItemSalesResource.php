<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DailyItemSalesResource\Pages;
use App\Models\Insights\DailyItemSales;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DailyItemSalesResource extends Resource
{
    protected static ?string $model = DailyItemSales::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?string $navigationLabel = '商品別売上';

    protected static ?string $modelLabel = '商品別売上';

    protected static ?string $pluralModelLabel = '商品別売上';

    protected static string | \UnitEnum | null $navigationGroup = '統計分析';

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'item-sales';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('business_date')
                    ->label('日付')
                    ->date('Y/m/d')
                    ->sortable(),
                TextColumn::make('item_name')
                    ->label('商品名')
                    ->searchable()
                    ->sortable()
                    ->limit(30),
                TextColumn::make('category_name')
                    ->label('カテゴリ')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('gray'),
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
                            ->default(fn () => DailyItemSales::max('business_date') ?? now()->format('Y-m-d')),
                    ])
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['date'],
                        fn (Builder $q, $date) => $q->where('business_date', $date)
                    ))
                    ->indicateUsing(fn (array $data) => $data['date'] ? '日付: ' . $data['date'] : null),
                SelectFilter::make('category_name')
                    ->label('カテゴリ')
                    ->options(fn () => DailyItemSales::whereNotNull('category_name')
                        ->distinct()
                        ->pluck('category_name', 'category_name')
                        ->toArray()
                    )
                    ->searchable(),
            ])
            ->defaultSort('sales_amount', 'desc')
            ->striped()
            ->defaultPaginationPageOption(100)
            ->paginationPageOptions([100, 500, 1000, 1500, 2000]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDailyItemSales::route('/'),
        ];
    }
}
