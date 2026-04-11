<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DailySettlementResource\Pages;
use App\Models\Insights\DailyPaymentSummary;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DailySettlementResource extends Resource
{
    protected static ?string $model = DailyPaymentSummary::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = '日計表';

    protected static ?string $modelLabel = '日計表';

    protected static ?string $pluralModelLabel = '日計表';

    protected static string | \UnitEnum | null $navigationGroup = '統計分析';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'daily-settlement';

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
                TextColumn::make('payment_type')
                    ->label('支払種別')
                    ->sortable()
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        '現金' => 'success',
                        'クレジット' => 'info',
                        '電子マネー' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('payment_label')
                    ->label('支払ラベル')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('amount')
                    ->label('金額')
                    ->money('JPY')
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('count')
                    ->label('件数')
                    ->numeric()
                    ->sortable()
                    ->alignEnd(),
            ])
            ->filters([
                Filter::make('business_date')
                    ->form([
                        DatePicker::make('date')
                            ->label('日付')
                            ->default(fn () => DailyPaymentSummary::max('business_date') ?? now()->format('Y-m-d')),
                    ])
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['date'],
                        fn (Builder $q, $date) => $q->where('business_date', $date)
                    ))
                    ->indicateUsing(fn (array $data) => $data['date'] ? '日付: ' . $data['date'] : null),
                SelectFilter::make('store_name')
                    ->label('店舗')
                    ->options(fn () => DailyPaymentSummary::distinct()
                        ->pluck('store_name', 'store_name')
                        ->toArray()
                    ),
                SelectFilter::make('payment_type')
                    ->label('支払種別')
                    ->options(fn () => DailyPaymentSummary::distinct()
                        ->pluck('payment_type', 'payment_type')
                        ->toArray()
                    ),
            ])
            ->defaultSort('amount', 'desc')
            ->groups([
                Tables\Grouping\Group::make('payment_type')
                    ->label('支払種別')
                    ->collapsible(),
                Tables\Grouping\Group::make('store_name')
                    ->label('店舗名')
                    ->collapsible(),
            ])
            ->striped()
            ->defaultPaginationPageOption(100)
            ->paginationPageOptions([100, 500, 1000, 1500, 2000]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDailySettlements::route('/'),
        ];
    }
}
