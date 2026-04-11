<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Insights ETL Scheduler
|--------------------------------------------------------------------------
|
| Realtime: every 15 minutes - updates hourly_store_sales + daily_store_sales
| Daily: every day at 02:00 AM - full daily ETL (all tables)
|
*/

Schedule::command('insights:generate-stats --mode=realtime')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/etl-realtime.log'));

Schedule::command('insights:generate-stats --mode=daily')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/etl-daily.log'));
