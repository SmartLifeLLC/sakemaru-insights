<?php

namespace App\Models\Insights;

use Illuminate\Database\Eloquent\Model;

class DailyStoreItemSales extends Model
{
    protected $table = 'daily_store_item_sales';

    protected $fillable = [
        'business_date',
        'ret_store_id',
        'store_name',
        'item_code',
        'item_id',
        'item_name',
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
