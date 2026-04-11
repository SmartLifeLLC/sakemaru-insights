<?php

namespace App\Models\Insights;

use Illuminate\Database\Eloquent\Model;

class DailyCategorySales extends Model
{
    protected $table = 'daily_category_sales';

    protected $fillable = [
        'business_date',
        'category_code',
        'item_category_id',
        'category_name',
        'sales_amount',
        'sales_qty',
        'return_amount',
        'gross_profit',
    ];

    protected $casts = [
        'business_date' => 'date',
    ];
}
