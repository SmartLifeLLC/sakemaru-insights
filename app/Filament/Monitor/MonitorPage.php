<?php

namespace App\Filament\Monitor;

use App\Models\Monitoring\Collector;
use App\Models\Monitoring\Incident;
use App\Models\Monitoring\Node;
use App\Monitoring\Enums\WorkflowStatus;
use App\Monitoring\Services\MonitorAccess;
use App\Monitoring\Services\MonitorHealthService;
use App\Monitoring\Services\SiteRegistry;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * 算盤 監視パネルのページ基底（v2 第6章）。Filament 標準のサイドバーは使わず、独自のシェル（サイドバー・上部バー・ドロワー）を描く。
 * 監視テーブルだけを読み、業務 SELECT・ログ全件走査・再集計を呼ばない。権限は各リクエストでサーバー側確認。
 */
abstract class MonitorPage extends Page
{
    protected static string $layout = 'filament-panels::components.layout.base';

    protected static bool $shouldRegisterNavigation = false;

    /** シェルのナビゲーションキー（dashboard / services / …） */
    protected static string $navKey = 'dashboard';

    protected static string $description = '';

    public ?string $flash = null;

    public ?string $flashLevel = null;

    public static function canAccess(): bool
    {
        return app(MonitorAccess::class)->can(auth()->user(), MonitorAccess::VIEW);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function getTitle(): string|Htmlable
    {
        return static::$title ?? '';
    }

    public static function navKey(): string
    {
        return static::$navKey;
    }

    public function description(): string
    {
        return static::$description;
    }

    protected function sites(): SiteRegistry
    {
        return app(SiteRegistry::class);
    }

    protected function access(): MonitorAccess
    {
        return app(MonitorAccess::class);
    }

    public function can(string $permission, ?string $siteCode = null): bool
    {
        return $this->access()->can(auth()->user(), $permission, $siteCode);
    }

    protected function actor(): array
    {
        return $this->access()->actor(auth()->user());
    }

    protected function ok(string $msg): void
    {
        $this->flash = $msg;
        $this->flashLevel = 'ok';
    }

    protected function fail(string $msg): void
    {
        $this->flash = $msg;
        $this->flashLevel = 'error';
    }

    /** @return array<int,array{id:int,code:string,name:string,label:string}> 閲覧可能サイト */
    public function siteOptions(): array
    {
        $user = auth()->user();

        return $this->sites()->all()
            ->filter(fn ($s) => $this->access()->can($user, MonitorAccess::VIEW, $s->application_code))
            ->map(fn ($s) => ['id' => (int) $s->id, 'code' => $s->application_code, 'name' => $s->display_name, 'label' => $this->sites()->label($s->application_code)])
            ->values()->all();
    }

    protected function viewableSiteIds(): array
    {
        return array_map(fn ($s) => $s['id'], $this->siteOptions());
    }

    protected function siteCode(int $siteId): string
    {
        return $this->sites()->siteById($siteId)?->application_code ?? '?';
    }

    public function siteLabel(?int $siteId): string
    {
        return $siteId ? $this->sites()->labelById($siteId) : '—';
    }

    /** @return array<int,array{level:string,text:string}> */
    public function banners(): array
    {
        try {
            return app(MonitorHealthService::class)->banners();
        } catch (\Throwable $e) {
            return [['level' => 'error', 'text' => '監視データを読み込めません（migration 未実行の可能性）: '.class_basename($e)]];
        }
    }

    /** シェル（サイドバー・上部バー）の表示データ。各値は小さな集合の索引検索だけ。 @return array<string,mixed> */
    public function shell(): array
    {
        $sites = $this->sites();
        $env = $sites->environment();
        $now = CarbonImmutable::now('UTC');
        $open = 0;
        $unack = 0;
        try {
            $ids = $this->viewableSiteIds();
            $open = Incident::query()->whereIn('site_id', $ids)->whereIn('workflow_status', WorkflowStatus::unresolvedValues())->count();
            $unack = Incident::query()->whereIn('site_id', $ids)->where('workflow_status', WorkflowStatus::OPEN->value)->count();
            $collectors = Collector::query()->where('node_code', 'not like', '%@%')->get(['last_heartbeat_at']);
            $nodes = Node::query()->where('enabled', true)->get(['last_heartbeat_at']);
            $fresh = fn ($t) => $t && CarbonImmutable::instance($t)->diffInSeconds($now) <= 300;
            $total = $collectors->count() + $nodes->count();
            $alive = $collectors->filter(fn ($c) => $fresh($c->last_heartbeat_at))->count() + $nodes->filter(fn ($n) => $fresh($n->last_heartbeat_at))->count();
        } catch (\Throwable) {
            $total = 0;
            $alive = 0;
        }
        $user = auth()->user();
        $name = (string) ($user->name ?? $user->email ?? '');

        return [
            'client' => $sites->clientName(),
            'env' => $sites->environmentLabel(),
            'env_class' => in_array($env, ['production', 'prd', 'prod'], true) ? 'prod' : (in_array($env, ['staging', 'stg'], true) ? 'stg' : 'local'),
            'deployment' => $sites->deploymentId(),
            'nav_key' => static::$navKey,
            'open' => $open,
            'unack' => $unack,
            'infra_total' => $total,
            'infra_alive' => $alive,
            'initials' => mb_substr($name !== '' ? $name : '?', 0, 2),
            'user_name' => $name,
            'title' => (string) $this->getTitle(),
            'description' => $this->description(),
            'now_jst' => $now->setTimezone('Asia/Tokyo')->format('Y/m/d H:i'),
        ];
    }

    public function dismissFlash(): void
    {
        $this->flash = null;
        $this->flashLevel = null;
    }

    private static function toCarbon($t): ?CarbonImmutable
    {
        return match (true) {
            ! $t => null,
            $t instanceof \DateTimeInterface => CarbonImmutable::instance($t),
            is_numeric($t) => CarbonImmutable::createFromTimestamp((int) $t, 'UTC'),
            default => CarbonImmutable::parse((string) $t, 'UTC'),
        };
    }

    public static function jst($t, string $format = 'm/d H:i'): string
    {
        $c = self::toCarbon($t);

        return $c ? $c->setTimezone('Asia/Tokyo')->format($format) : '—';
    }

    public static function ago($t): string
    {
        $c = self::toCarbon($t);
        if (! $c) {
            return '—';
        }
        $s = (int) $c->diffInSeconds(CarbonImmutable::now('UTC'));

        return match (true) {
            $s < 60 => $s.'秒前',
            $s < 3600 => intdiv($s, 60).'分前',
            $s < 86400 => intdiv($s, 3600).'時間前',
            default => intdiv($s, 86400).'日前',
        };
    }

    // ------------------------------------------------------------------ アラートの一時停止（全ページ共通のボタン）

    public function alertPause(): ?array
    {
        return app(\App\Monitoring\Services\NotificationSettings::class)->pause();
    }

    public function pauseAlertsAction(): Action
    {
        // 運用中（緑）。押すと一時停止の確認。停止中は resumeAlertsAction（赤）が代わりに表示される
        return Action::make('pauseAlerts')->label('通知 運用中')->color('success')->icon('heroicon-o-check-circle')->extraAttributes(['class' => 'alert-state-running', 'title' => '押すと新規アラートを一時停止します'])
            ->modalHeading('新規アラートを一時停止する')
            ->modalDescription('対応中に Slack・メール・電話の通知を止めます。監視・記録・エラー管理は止まりません。通知は保留され、再開後にまとめて送ります（確認・対応中になった案件の電話は送りません）。期限が来ると自動で再開します。')
            ->modalSubmitActionLabel('停止する')->modalCancelActionLabel('停止せず閉じる')->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
            ->visible(fn () => $this->can(MonitorAccess::RESPOND) && ! $this->alertPause())
            ->schema([
                Select::make('minutes')->label('停止時間')->options([30 => '30 分', 60 => '1 時間', 120 => '2 時間', 240 => '4 時間'])->default(60)->required(),
                Textarea::make('reason')->label('理由（必須・全員に表示されます）')->rows(2)->required()->minLength(2),
            ])
            ->action(function (array $data) {
                try {
                    $this->access()->authorize(auth()->user(), MonitorAccess::RESPOND);
                    $p = app(\App\Monitoring\Services\NotificationSettings::class)->pauseAlerts((int) $data['minutes'], $this->actor(), (string) $data['reason']);
                    $this->ok('アラートを '.$p['until']->setTimezone('Asia/Tokyo')->format('H:i').' まで停止しました。');
                } catch (\App\Monitoring\Exceptions\AccessDenied) {
                    $this->fail('アラートの停止は対応権限（monitor.respond）が必要です。');
                } catch (\InvalidArgumentException $e) {
                    $this->fail($e->getMessage());
                }
            });
    }

    public function resumeAlertsAction(): Action
    {
        $p = $this->alertPause();
        $label = '通知 停止中 〜'.($p ? $p['until']->setTimezone('Asia/Tokyo')->format('H:i') : '');

        return Action::make('resumeAlerts')->label($label)->color('danger')->icon('heroicon-o-pause-circle')->requiresConfirmation()->extraAttributes(['class' => 'alert-state-paused', 'title' => ($p['reason'] ?? '').'（押すと再開）'])
            ->modalHeading('アラートを再開する')->modalDescription('保留中の通知をまとめて送ります。')->modalSubmitActionLabel('再開する')->modalCancelActionLabel('再開せず閉じる')->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
            ->visible(fn () => $this->can(MonitorAccess::RESPOND) && (bool) $this->alertPause())
            ->action(function () {
                try {
                    $this->access()->authorize(auth()->user(), MonitorAccess::RESPOND);
                    app(\App\Monitoring\Services\NotificationSettings::class)->resumeAlerts($this->actor());
                    $this->ok('アラートを再開しました。');
                } catch (\App\Monitoring\Exceptions\AccessDenied) {
                    $this->fail('アラートの再開は対応権限（monitor.respond）が必要です。');
                }
            });
    }

    // ------------------------------------------------------------------ お知らせ（監視基盤の警告・未確認エラー）: 本文のバナーではなくベルのモーダルに出す

    public function noticesAction(): Action
    {
        $banners = $this->banners();
        $unack = (int) ($this->shell()['unack'] ?? 0);
        $count = count($banners) + $unack;

        return Action::make('notices')->iconButton()->icon('heroicon-o-bell')->color('gray')
            ->label('お知らせ')->tooltip($count > 0 ? "お知らせ {$count} 件" : 'お知らせはありません')
            ->badge($count > 0 ? (string) $count : null)->badgeColor(collect($banners)->contains(fn ($b) => $b['level'] === 'error') ? 'danger' : 'warning')
            ->modalHeading('お知らせ')->modalDescription('監視基盤の設定・配送の警告と、未確認のエラーです。')
            ->modalSubmitAction(false)->modalCancelActionLabel('閉じる')->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])->modalWidth('2xl')
            ->modalContent(fn () => view('monitor.partials.notices', [
                'banners' => $this->banners(),
                'unack' => $unack,
                'incidentsUrl' => \App\Filament\Monitor\Pages\Incidents::getUrl(['tab' => 'unacknowledged']),
                'notificationsUrl' => \App\Filament\Monitor\Pages\Notifications::getUrl(),
            ]));
    }
}
