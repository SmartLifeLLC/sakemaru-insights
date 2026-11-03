<?php

namespace App\Models\Monitoring;

class Contact extends MonitorModel
{
    protected $table = 'monitor_contacts';

    public $timestamps = true;

    protected $casts = [
        'enabled' => 'bool',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** 表示用に番号の中央を伏字にする（+81 90 ****5678） */
    public function maskedPhone(): string
    {
        $p = (string) $this->phone_e164;

        return strlen($p) > 6 ? substr($p, 0, 5).str_repeat('*', max(0, strlen($p) - 9)).substr($p, -4) : '****';
    }
}
