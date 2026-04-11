<?php

namespace App\Models\Insights;

use Illuminate\Database\Eloquent\Model;

class DailyPaymentSummary extends Model
{
    protected $table = 'daily_payment_summary';

    protected $fillable = [
        'business_date',
        'ret_store_id',
        'store_name',
        'payment_type',
        'payment_label',
        'amount',
        'count',
    ];

    protected $casts = [
        'business_date' => 'date',
    ];
}
