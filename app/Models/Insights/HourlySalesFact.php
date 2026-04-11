<?php

namespace App\Models\Insights;

use Illuminate\Database\Eloquent\Model;

class HourlySalesFact extends Model
{
    protected $table = 'hourly_sales_fact';

    protected $fillable = [
        'business_date',
        'ret_store_id',
        'time_slot',
        'item_code',
        'item_id',
        'category_code',
        'item_category_id',
        'sales_qty',
        'sales_amount',
        'return_qty',
        'return_amount',
        'gross_profit',
        'cost_amount',
    ];

    protected $casts = [
        'business_date' => 'date',
        'cost_amount' => 'decimal:2',
    ];
}
