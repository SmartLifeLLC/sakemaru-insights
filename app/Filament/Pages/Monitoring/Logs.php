<?php

namespace App\Filament\Pages\Monitoring;

/** 旧 URL /admin/monitor/logs → /monitor へ転送 */
class Logs extends MonitoringPage
{
    protected static ?string $slug = 'monitor/logs';

    protected function target(): string
    {
        return \App\Filament\Monitor\Pages\Logs::getUrl(request()->query(), panel: 'monitor');
    }
}
