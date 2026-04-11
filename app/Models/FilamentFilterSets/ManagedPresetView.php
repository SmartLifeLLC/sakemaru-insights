<?php

namespace App\Models\FilamentFilterSets;

use Archilex\AdvancedTables\Models\ManagedPresetView as BaseManagedPresetView;

class ManagedPresetView extends BaseManagedPresetView
{
    protected $connection = 'sakemaru';

    protected $table = 'ins_filament_filter_sets_managed_preset_views';
}
