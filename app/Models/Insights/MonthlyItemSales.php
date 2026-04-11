<?php

namespace App\Models\Insights;

use Illuminate\Database\Eloquent\Model;

class MonthlyItemSales extends Model
{
    protected $table = 'monthly_item_sales';

    protected $fillable = [
        'year_month',
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
}
