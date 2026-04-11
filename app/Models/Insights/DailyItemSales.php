<?php

namespace App\Models\Insights;

use Illuminate\Database\Eloquent\Model;

class DailyItemSales extends Model
{
    protected $table = 'daily_item_sales';

    protected $fillable = [
        'business_date',
        'item_code',
        'item_id',
        'item_name',
        'category_code',
        'item_category_id',
        'category_name',
        'sales_amount',
        'sales_qty',
        'return_amount',
        'return_qty',
        'gross_profit',
    ];

    protected $casts = [
        'business_date' => 'date',
    ];
}
