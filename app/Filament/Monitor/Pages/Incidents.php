<?php

namespace App\Filament\Monitor\Pages;

use App\Filament\Monitor\MonitorPage;
use App\Models\Monitoring\Incident;
use App\Monitoring\Enums\WorkflowStatus;
use App\Monitoring\Services\IncidentQueryService;
use App\Monitoring\Services\KeysetPage;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;

/** エラー管理（v1 11.3 を新テーマへ。V-100）。未確認・重大を専用タブで強調し、行クリックでドロワー（概要・対応状況の変更）。 */
class Incidents extends MonitorPage
{
    protected static ?string $title = 'エラー管理';

    protected static ?string $slug = 'incidents';

    protected static string $navKey = 'incidents';

    protected static string $description = '影響度を確認し、優先度の高い問題から対応します。';

    protected string $view = 'monitor.pages.incidents';

    public ?string $openId = null;

    #[Url]
    public string $tab = 'active';

    #[Url]
    public ?string $site = null;

    #[Url]
    public ?string $severity = null;

    #[Url(as: 'check')]
    public ?string $checkKey = null;

    #[Url(as: 'q')]
    public ?string $search = null;

    #[Url(as: 'size')]
    public int $pageSize = 20;

    #[Url]
    public ?string $cursor = null;

    public ?string $error = null;

    /** 一括対処で選択中のエラー番号（表示中ページの中から） @var array<int,string> */
    public array $selected = [];

    public function mount(): void
    {
        parent::mount();
        if (! in_array($this->pageSize, (array) config('monitoring.ui.page_size_options', [20, 50, 100]), true)) {
            $this->pageSize = (int) config('monitoring.ui.page_size_default', 20);
        }
    }

    public function updated(string $prop): void
    {
        if (in_array($prop, ['site', 'severity', 'checkKey', 'pageSize'], true)) {
            $this->cursor = null;
        }
    }

    /** @return int[] */
    protected function scopedSiteIds(): array
    {
        $ids = $this->viewableSiteIds();
        if ($this->site && ($s = $this->sites()->site($this->site)) && in_array((int) $s->id, $ids, true)) {
            return [(int) $s->id];
        }

        return $ids;
    }

    public function openIncident(string $publicId): void
    {
        $this->openId = $publicId;
    }

    public function closeDrawer(): void
    {
        $this->openId = null;
    }

    public function opened(): ?Incident
    {
        return $this->openId ? Incident::query()->whereIn('site_id', $this->viewableSiteIds())->where('public_id', $this->openId)->first() : null;
    }

    /** ドロワーからの対応状況変更（IncidentService 経由・楽観ロック・監査。V-100） */
    public function quick(string $action, int $version): void
    {
        $incident = $this->opened();
        if (! $incident || ! in_array($action, ['acknowledge', 'start'], true)) {
            return;
        }
        try {
            $this->access()->authorize(auth()->user(), \App\Monitoring\Services\MonitorAccess::RESPOND, $this->siteCode((int) $incident->site_id));
            app(\App\Monitoring\Services\IncidentService::class)->act($incident, $action, $version, $this->actor(), ['reason' => $action === 'start' ? 'エラー管理の一覧から対応開始' : null], "ui-list:{$incident->id}:{$action}:{$version}");
            $this->ok($action === 'acknowledge' ? $incident->public_id.' を確認済みにしました。' : $incident->public_id.' の対応を開始しました。');
        } catch (\App\Monitoring\Exceptions\VersionConflict|\App\Monitoring\Exceptions\InvalidTransition|\App\Monitoring\Exceptions\AccessDenied $e) {
            $this->fail($e->getMessage());
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->cursor = null;
        $this->selected = [];
    }

    /** 表示中ページの全選択 / 解除 */
    public function toggleSelectAll(): void
    {
        $ids = array_map(fn ($i) => $i->public_id, $this->page()?->items ?? []);
        $this->selected = count(array_intersect($ids, $this->selected)) === count($ids) && $ids !== [] ? [] : $ids;
    }

    // ------------------------------------------------------------------ 一括対処（選択した案件に同じ操作を順に適用。1 件ずつ楽観ロック・監査）

    public const BULK_ACTIONS = [
        'acknowledge' => '確認済みにする',
        'start' => '対応開始にする',
        'assign' => '担当者を割り当てる',
        'mark_recovered' => '復旧確認を記録する（人手で確認した）',
        'resolve' => '対処完了（解決）にする ※復旧確認済みの案件だけ',
        'resolve_close' => '解決して終了する ※復旧確認済みの案件だけ',
        'close' => '終了する ※解決済みの案件だけ',
    ];

    public function bulkAction(): Action
    {
        return Action::make('bulk')->label(fn () => '選択した '.count($this->selected).' 件を一括対処')->color('primary')->icon('heroicon-o-check-circle')
            ->visible(fn () => $this->can(\App\Monitoring\Services\MonitorAccess::RESPOND))
            ->disabled(fn () => $this->selected === [])
            ->modalHeading(fn () => '選択した '.count($this->selected).' 件に同じ対処を記録する')
            ->modalDescription('1 件ずつ順に記録します（監査・通知の取消も個別に行われます）。状態が合わない案件（例: 未復旧の案件を解決にする）はスキップし、件数を表示します。')
            ->modalSubmitActionLabel('一括で記録する')->modalCancelActionLabel('記録せず閉じる')->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])->modalWidth('2xl')
            ->schema([
                Select::make('action')->label('対処')->options(self::BULK_ACTIONS)->default('acknowledge')->required()->live(),
                TextInput::make('assignee_name')->label('担当者名')->default(fn () => auth()->user()?->name)->maxLength(100)->visible(fn (Get $get) => $get('action') === 'assign')->required(fn (Get $get) => $get('action') === 'assign'),
                Select::make('cause_category')->label('原因分類')->options([
                    'data_delay' => '元データの遅延・未受信', 'batch_failure' => 'バッチ失敗・未実行', 'lock_contention' => '排他ロック競合', 'config' => '設定・スケジュール不備',
                    'external' => '外部システム・接続先', 'application_bug' => 'アプリ不具合', 'infra' => 'インフラ・容量', 'monitoring' => '監視側の問題（誤検知含む）', 'undetermined' => '未確定',
                ])->visible(fn (Get $get) => in_array($get('action'), ['resolve', 'resolve_close'], true))->required(fn (Get $get) => in_array($get('action'), ['resolve', 'resolve_close'], true)),
                Textarea::make('action_taken')->label('実施内容')->rows(2)->visible(fn (Get $get) => in_array($get('action'), ['resolve', 'resolve_close'], true))->required(fn (Get $get) => in_array($get('action'), ['resolve', 'resolve_close'], true)),
                Textarea::make('verification')->label('検証結果（どう確認したか）')->rows(2)->visible(fn (Get $get) => in_array($get('action'), ['resolve', 'resolve_close'], true))->required(fn (Get $get) => in_array($get('action'), ['resolve', 'resolve_close'], true)),
                Textarea::make('reason')->label(fn (Get $get) => $get('action') === 'mark_recovered' ? '再確認した内容（必須）' : 'コメント（全件に同じ内容を記録）')->rows(2)->required(fn (Get $get) => $get('action') === 'mark_recovered'),
            ])
            ->action(fn (array $data) => $this->performBulk($data));
    }

    private function performBulk(array $data): void
    {
        $action = (string) ($data['action'] ?? '');
        if (! isset(self::BULK_ACTIONS[$action]) || $this->selected === []) {
            return;
        }
        $incidents = Incident::query()->whereIn('site_id', $this->viewableSiteIds())->whereIn('public_id', $this->selected)->orderBy('id')->get();
        $ok = 0;
        $skipped = [];
        $svc = app(\App\Monitoring\Services\IncidentService::class);
        foreach ($incidents as $incident) {
            try {
                $this->access()->authorize(auth()->user(), \App\Monitoring\Services\MonitorAccess::RESPOND, $this->siteCode((int) $incident->site_id));
                $steps = $action === 'resolve_close' ? ['resolve', 'close'] : [$action];
                DB::connection('monitor_store')->transaction(function () use ($svc, $incident, $steps, $data) {
                    foreach ($steps as $step) {
                        $input = match ($step) {
                            'assign' => ['assignee_name' => (string) $data['assignee_name'], 'assignee_id' => ($data['assignee_name'] ?? null) === auth()->user()?->name ? auth()->id() : null],
                            'resolve' => ['cause_category' => $data['cause_category'] ?? null, 'action_taken' => $data['action_taken'] ?? null, 'verification' => $data['verification'] ?? null, 'reason' => $data['reason'] ?? null],
                            default => ['reason' => ($data['reason'] ?? null) ?: ($step === 'start' ? 'エラー管理の一括対処' : null)],
                        };
                        $incident = $svc->act($incident, $step, (int) $incident->version, $this->actor(), $input, "ui-bulk:{$incident->id}:{$step}:{$incident->version}");
                    }
                }, 2);
                $ok++;
            } catch (\App\Monitoring\Exceptions\VersionConflict|\App\Monitoring\Exceptions\InvalidTransition|\App\Monitoring\Exceptions\AccessDenied $e) {
                $skipped[] = $incident->public_id.'（'.$e->getMessage().'）';
            }
        }
        $this->selected = [];
        $msg = self::BULK_ACTIONS[$action].': '.$ok.' 件を記録しました。';
        if ($skipped !== []) {
            $msg .= ' スキップ '.count($skipped).' 件: '.implode(' / ', array_slice($skipped, 0, 5)).(count($skipped) > 5 ? ' …' : '');
            $this->flash = $msg;
            $this->flashLevel = $ok > 0 ? 'warn' : 'error';
        } else {
            $this->ok($msg);
        }
    }

    public function applyFilters(): void
    {
        $this->cursor = null;
    }

    public function resetFilters(): void
    {
        $this->reset(['site', 'severity', 'checkKey', 'search', 'cursor']);
        $this->tab = 'active';
    }

    public function goTo(?string $cursor): void
    {
        $this->cursor = $cursor;
    }

    public function refreshNewest(): void
    {
        $this->cursor = null;
    }

    protected function filters(): array
    {
        $siteIds = $this->scopedSiteIds();

        return ['site_ids' => $siteIds, 'tab' => $this->tab, 'severities' => $this->severity ? [$this->severity] : [], 'check_key' => $this->checkKey, 'search' => $this->search];
    }

    public function page(): ?KeysetPage
    {
        $this->error = null;
        try {
            return app(IncidentQueryService::class)->page($this->filters(), $this->cursor, $this->pageSize);
        } catch (\InvalidArgumentException $e) {
            $this->error = 'ページの位置情報が無効または期限切れです。先頭から表示します。';
            $this->cursor = null;

            return null;
        }
    }

    /** タブ件数（未解決は小さい集合。解決済みは数えない） */
    public function tabCounts(): array
    {
        $rows = Incident::query()->selectRaw('workflow_status, observation_state, severity, COUNT(*) as c')
            ->whereIn('site_id', $this->scopedSiteIds())
            ->whereIn('workflow_status', WorkflowStatus::unresolvedValues())
            ->groupBy('workflow_status', 'observation_state', 'severity')->get();
        $c = ['unacknowledged' => 0, 'critical' => 0, 'active' => 0, 'recovered' => 0];
        foreach ($rows as $r) {
            $n = (int) $r->c;
            $c['active'] += $n;
            if ($r->workflow_status->value === 'OPEN') {
                $c['unacknowledged'] += $n;
            }
            if ($r->severity->value === 'CRITICAL') {
                $c['critical'] += $n;
            }
            if ($r->observation_state->value === 'RECOVERED') {
                $c['recovered'] += $n;
            }
        }

        return $c;
    }
}
