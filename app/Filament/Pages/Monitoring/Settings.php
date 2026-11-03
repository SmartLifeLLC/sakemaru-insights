<?php

namespace App\Filament\Pages\Monitoring;

/** 旧 URL /admin/monitor/settings → /monitor へ転送 */
class Settings extends MonitoringPage
{
    protected static ?string $slug = 'monitor/settings';

    protected function target(): string
    {
        return \App\Filament\Monitor\Pages\Rules::getUrl(request()->query(), panel: 'monitor');
    }
}
