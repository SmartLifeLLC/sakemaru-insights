<?php

namespace App\Providers\Filament;

use Archilex\AdvancedTables\Plugin\AdvancedTablesPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use WatheqAlshowaiter\FilamentStickyTableHeader\StickyTableHeaderPlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->topNavigation()
            ->maxContentWidth('full')
            ->breadcrumbs(false)
            ->colors([
                'primary' => Color::Indigo, // Insights uses Indigo to differentiate from WMS (Amber)
            ])
            ->darkMode(false)
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                //                Dashboard::class,
            ])
            // 運用監視は /monitor パネルへ移動（v2 第6.1）。/admin のメニューにはリンクだけを置く
            ->navigationItems([
                \Filament\Navigation\NavigationItem::make('運用監視')
                    ->url('/monitor')
                    ->icon('heroicon-o-shield-check')
                    ->sort(90)
                    ->visible(fn () => app(\App\Monitoring\Services\MonitorAccess::class)->can(auth()->user(), \App\Monitoring\Services\MonitorAccess::VIEW)),
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                // AuthenticateSession::class, // Disabled for cross-app session sharing
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->authGuard('web')
            ->plugins([
                StickyTableHeaderPlugin::make(),
                AdvancedTablesPlugin::make()
                    ->userViewsEnabled(false)
                    ->globalUserViewsManageable(false)
                    ->viewManagerEnabled(false)
                    ->resourceEnabled(false)
                    ->userView(\App\Models\FilamentFilterSets\UserView::class)
                    ->managedUserView(\App\Models\FilamentFilterSets\ManagedUserView::class)
                    ->managedPresetView(\App\Models\FilamentFilterSets\ManagedPresetView::class)
                    ->managedDefaultView(\App\Models\FilamentFilterSets\ManagedDefaultView::class),
            ]);
    }
}
