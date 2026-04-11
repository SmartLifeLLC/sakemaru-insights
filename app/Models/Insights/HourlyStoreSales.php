<?php

namespace App\Models\Insights;

use Illuminate\Database\Eloquent\Model;

class HourlyStoreSales extends Model
{
    protected $table = 'hourly_store_sales';

    protected $fillable = [
        'business_date',
        'ret_store_id',
        'store_name',
        'time_slot',
        'sales_amount',
        'sales_qty',
        'gross_profit',
        'customer_count',
    ];

    protected $casts = [
        'business_date' => 'date',
    ];
}
