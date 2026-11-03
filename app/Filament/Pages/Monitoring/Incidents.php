<?php

namespace App\Filament\Pages\Monitoring;

/** 旧 URL /admin/monitor/incidents → /monitor へ転送 */
class Incidents extends MonitoringPage
{
    protected static ?string $slug = 'monitor/incidents';

    protected function target(): string
    {
        return \App\Filament\Monitor\Pages\Incidents::getUrl(request()->query(), panel: 'monitor');
    }
}
