<?php

namespace App\Filament\Pages\Monitoring;

use Filament\Panel;

/** 旧 URL /admin/monitor/incidents/{publicId} → /monitor/incidents/{publicId} へ転送 */
class IncidentDetail extends MonitoringPage
{
    protected static ?string $slug = 'monitor/incidents/detail';

    public string $publicId = '';

    public static function getRoutePath(Panel $panel): string
    {
        return '/monitor/incidents/{publicId}';
    }

    public function mount(string $publicId = ''): void
    {
        $this->publicId = strtoupper($publicId);
        parent::mount();
    }

    protected function target(): string
    {
        return \App\Filament\Monitor\Pages\IncidentDetail::getUrl(['publicId' => $this->publicId], panel: 'monitor');
    }
}
