<?php

namespace App\Models\Insights;

use Illuminate\Database\Eloquent\Model;

class DimItem extends Model
{
    protected $table = 'dim_item';

    protected $fillable = [
        'item_code',
        'item_name',
        'category_code',
        'brand',
    ];
}
