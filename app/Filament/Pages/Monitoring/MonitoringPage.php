<?php

namespace App\Filament\Pages\Monitoring;

use Filament\Pages\Page;

/**
 * v1（/admin/monitor/…）の旧 URL。画面は /monitor パネルへ移動した（v2 第6.1）。
 * Slack 通知や手順書に残った旧リンクを新しい画面へ転送するためだけに残す。メニューには出さない。
 */
abstract class MonitoringPage extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'monitor.redirect';

    abstract protected function target(): string;

    public function mount(): void
    {
        $this->redirect($this->target());
    }
}
