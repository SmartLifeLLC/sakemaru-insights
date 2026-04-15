<?php

namespace App\Filament\Resources\ItemCategoryResource\Pages;

use App\Filament\Resources\ItemCategoryResource;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Resources\Pages\ListRecords;

class ListItemCategories extends ListRecords
{
    use AdvancedTables;

    protected static string $resource = ItemCategoryResource::class;

    public function getPresetViews(): array
    {
        return [
            'depth1' => PresetView::make()
                ->modifyQueryUsing(fn ($query) => $query->where('depth', 1))
                ->favorite()
                ->default()
                ->label('大分類'),
            'depth2' => PresetView::make()
                ->modifyQueryUsing(fn ($query) => $query->where('depth', 2))
                ->favorite()
                ->label('中分類'),
            'depth3' => PresetView::make()
                ->modifyQueryUsing(fn ($query) => $query->where('depth', 3))
                ->favorite()
                ->label('小分類'),
        ];
    }
}
