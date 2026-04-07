<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class ClientSetting extends Model
{
    protected $connection = 'sakemaru';

    protected $table = 'client_settings';

    public static function systemDate(bool $defaultNow = false): ?Carbon
    {
        $setting = static::first();

        if ($setting?->system_date) {
            return new Carbon($setting->system_date);
        }

        return $defaultNow ? now() : null;
    }
}
