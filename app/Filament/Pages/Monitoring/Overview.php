<?php

namespace App\Filament\Pages\Monitoring;

/** 旧 URL /admin/monitor → /monitor へ転送 */
class Overview extends MonitoringPage
{
    protected static ?string $slug = 'monitor';

    protected function target(): string
    {
        return \App\Filament\Monitor\Pages\Dashboard::getUrl(request()->query(), panel: 'monitor');
    }
}
