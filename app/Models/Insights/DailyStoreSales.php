<?php

namespace App\Models\Insights;

use Illuminate\Database\Eloquent\Model;

class DailyStoreSales extends Model
{
    protected $table = 'daily_store_sales';

    protected $fillable = [
        'business_date',
        'ret_store_id',
        'store_name',
        'area',
        'sales_amount',
        'sales_qty',
        'return_amount',
        'return_qty',
        'gross_profit',
        'customer_count',
        'unit_price',
        'gross_profit_rate',
    ];

    protected $casts = [
        'business_date' => 'date',
        'gross_profit_rate' => 'decimal:2',
    ];
}
