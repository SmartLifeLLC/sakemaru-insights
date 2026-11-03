<?php

namespace App\Filament\Pages\Monitoring;

/** 旧 URL /admin/monitor/runbooks → /monitor へ転送 */
class Runbooks extends MonitoringPage
{
    protected static ?string $slug = 'monitor/runbooks';

    protected function target(): string
    {
        return \App\Filament\Monitor\Pages\Runbooks::getUrl(request()->query(), panel: 'monitor');
    }
}
