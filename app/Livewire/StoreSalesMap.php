<?php

namespace App\Livewire;

use App\Models\Insights\DailyStoreSales;
use App\Models\Insights\DimStore;
use Livewire\Attributes\Reactive;
use Livewire\Component;

class StoreSalesMap extends Component
{
    #[Reactive]
    public ?string $filterDate = null;

    public function render()
    {
        return view('livewire.store-sales-map', [
            'mapData' => $this->getMapData(),
        ]);
    }

    public function getMapData(): array
    {
        if (! $this->filterDate) {
            return [];
        }

        // POS実店舗で緯度経度のある店舗をキー付きで取得
        $geoStores = DimStore::query()
            ->where('has_pos', true)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get()
            ->keyBy('store_name');

        // 当日の店舗別売上を取得してマージ
        return DailyStoreSales::query()
            ->where('business_date', $this->filterDate)
            ->get()
            ->filter(fn ($row) => $geoStores->has($row->store_name))
            ->map(function ($row) use ($geoStores) {
                $store = $geoStores->get($row->store_name);

                return [
                    'name' => $row->store_name,
                    'lat' => (float) $store->latitude,
                    'lng' => (float) $store->longitude,
                    'postal_code' => $store->postal_code,
                    'sales' => (int) $row->sales_amount,
                    'customers' => (int) $row->customer_count,
                ];
            })
            ->values()
            ->toArray();
    }
}
