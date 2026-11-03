<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * 算盤 監視（/monitor）。既存の /admin（統計画面）とは別パネル。同じ認証ガード・セッションを使う（v2 第6.1 / V-090）。
 * サイドバー・上部バー・ドロワーは独自レイアウト（resources/views/components/monitor/shell.blade.php）で描画する。
 */
class MonitorPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('monitor')
            ->path('monitor')
            ->login()
            ->brandName('算盤 監視')
            ->colors(['primary' => Color::Amber, 'gray' => Color::Slate])
            ->darkMode(true)
            ->maxContentWidth('full')
            ->breadcrumbs(false)
            ->discoverPages(in: app_path('Filament/Monitor/Pages'), for: 'App\Filament\Monitor\Pages')
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('monitor.partials.head'))
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([Authenticate::class])
            ->authGuard('web');
    }
}
