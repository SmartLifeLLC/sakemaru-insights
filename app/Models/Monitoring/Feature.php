<?php

namespace App\Models\Monitoring;

class Feature extends MonitorModel
{
    protected $table = 'monitor_features';

    public $timestamps = true;

    protected $casts = [
        'enabled' => 'bool',
        'recommended_enabled' => 'bool',
        'recommended_checked_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** 推奨値と設定が食い違っているか（「要確認」バッジ） */
    public function needsReview(): bool
    {
        return $this->recommended_enabled !== null && $this->recommended_enabled !== $this->enabled;
    }
}
