<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // 運用監視: 台帳系はリクエスト（またはジョブ）単位で共有し、キャッシュの食い違いを防ぐ
        $this->app->scoped(\App\Monitoring\Services\SiteRegistry::class);
        $this->app->scoped(\App\Monitoring\Services\CheckRegistry::class);
        $this->app->scoped(\App\Monitoring\Services\FeatureRegistry::class);
        $this->app->scoped(\App\Monitoring\Services\SecretStore::class);
        $this->app->scoped(\App\Monitoring\Services\NotificationSettings::class);
        $this->app->scoped(\App\Monitoring\Services\CounterService::class); // 取込単位でまとめて flush するため共有
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 本番は常に HTTPS（WMS と同じ）。ELB 配下で生成される資産・リンク URL を https にする
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
