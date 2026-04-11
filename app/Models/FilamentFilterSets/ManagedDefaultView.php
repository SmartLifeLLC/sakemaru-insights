<?php

namespace App\Models\FilamentFilterSets;

use Archilex\AdvancedTables\Models\ManagedDefaultView as BaseManagedDefaultView;

class ManagedDefaultView extends BaseManagedDefaultView
{
    protected $connection = 'sakemaru';

    protected $table = 'ins_filament_filter_sets_managed_default_views';
}
