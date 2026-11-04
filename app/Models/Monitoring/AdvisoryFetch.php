<?php

namespace App\Models\Monitoring;

/** 情報源ごとの取得履歴（成功・失敗・件数）。 */
class AdvisoryFetch extends MonitorModel
{
    protected $table = 'monitor_advisory_fetches';

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
