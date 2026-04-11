<?php

namespace App\Models\Insights;

use Illuminate\Database\Eloquent\Model;

class MonthlyStoreSales extends Model
{
    protected $table = 'monthly_store_sales';

    protected $fillable = [
        'year_month',
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
        'gross_profit_rate' => 'decimal:2',
    ];
}
