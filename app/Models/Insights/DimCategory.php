<?php

namespace App\Models\Insights;

use Illuminate\Database\Eloquent\Model;

class DimCategory extends Model
{
    protected $table = 'dim_category';

    protected $fillable = [
        'category_code',
        'item_category_id',
        'category_name',
    ];
}
