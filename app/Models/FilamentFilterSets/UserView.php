<?php

namespace App\Models\FilamentFilterSets;

use Archilex\AdvancedTables\Models\UserView as BaseUserView;
use Archilex\AdvancedTables\Support\Config;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class UserView extends BaseUserView
{
    protected $connection = 'sakemaru';

    protected $table = 'ins_filament_filter_sets';

    public function userManagedUserViews(): BelongsToMany
    {
        return $this->belongsToMany(
            Config::getUser(),
            'ins_filament_filter_set_user',
            foreignPivotKey: 'filter_set_id',
            relatedPivotKey: 'user_id'
        )->withPivot('sort_order', 'is_visible', Config::getTenantColumn());
    }
}
