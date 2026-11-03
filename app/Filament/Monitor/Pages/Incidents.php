<?php

namespace App\Filament\Monitor\Pages;

use App\Filament\Monitor\MonitorPage;
use App\Models\Monitoring\Incident;
use App\Monitoring\Enums\WorkflowStatus;
use App\Monitoring\Services\IncidentQueryService;
use App\Monitoring\Services\KeysetPage;
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
