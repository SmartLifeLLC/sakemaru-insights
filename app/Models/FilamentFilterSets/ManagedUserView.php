<?php

namespace App\Models\FilamentFilterSets;

use Archilex\AdvancedTables\Models\ManagedUserView as BaseManagedUserView;

class ManagedUserView extends BaseManagedUserView
{
    protected $connection = 'sakemaru';

    protected $table = 'ins_filament_filter_set_user';
}
