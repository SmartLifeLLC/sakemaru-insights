<?php

namespace App\Filament\Resources;

use App\Constants\TableDefaults;
use App\Filament\Resources\ItemCategoryResource\Pages;
use App\Models\Sakemaru\ItemCategory;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Tables;
use Filament\Tables\Table;

class ItemCategoryResource extends Resource
{
    protected static ?string $model = ItemCategory::class;

    protected static ?string $label = '商品カテゴリ';

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static \UnitEnum|string|null $navigationGroup = 'マスタ管理';

    protected static ?int $navigationSort = 51;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('コード')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('name')
                    ->label('カテゴリ名')
                    ->searchable(),
                Tables\Columns\TextColumn::make('depth')
                    ->label('階層')
                    ->sortable()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        1 => '大分類',
                        2 => '中分類',
                        3 => '小分類',
                        default => $state,
                    }),
            ])
            ->defaultSort('code')
            ->defaultPaginationPageOption(TableDefaults::PAGINATION_PAGE_OPTION)
            ->actions([
                Action::make('detail')
                    ->label('詳細')
                    ->icon('heroicon-o-eye')
                    ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                    ->modalHeading(fn ($record) => "[{$record->code}] {$record->name}")
                    ->modalWidth('lg')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->modalSubmitAction(
                        fn ($action) => $action
                            ->makeModalSubmitAction('submit', [])
                            ->label('保存')
                            ->color('danger')
                    )
                    ->modalCancelActionLabel('保存せず閉じる')
                    ->fillForm(fn ($record) => [
                        'name' => $record->name,
                    ])
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('カテゴリ名')
                            ->required(),
                    ])
                    ->action(function ($record, array $data) {
                        $record->update(['name' => $data['name']]);
                    }),
            ])
            ->striped()
            ->recordUrl(null)
            ->recordActionsColumnLabel('')
            ->extraAttributes(['class' => 'sticky-actions']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListItemCategories::route('/'),
        ];
    }
}
