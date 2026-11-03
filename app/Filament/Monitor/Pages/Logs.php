<?php

namespace App\Filament\Monitor\Pages;

use App\Filament\Monitor\MonitorPage;
use App\Models\Monitoring\Check as CheckModel;
use App\Models\Monitoring\Incident;
use App\Models\Monitoring\LogEntry;
use App\Monitoring\Services\KeysetPage;
use App\Monitoring\Services\LogQueryService;
use App\Monitoring\Services\RunbookRegistry;
use App\Monitoring\Support\Redactor;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** 監視ログ（v1 11.2 を新テーマへ。V-102〜104）。キーセットページング、全件 COUNT なし、CSV は表示中ページだけ。 */
class Logs extends MonitorPage
{
    protected static ?string $title = '監視ログ';

    protected static ?string $slug = 'logs';

    protected static string $navKey = 'logs';

    protected static string $description = '正常・警告・エラーを含む監視結果を検索します。保存済みデータだけを表示します（既定は直近 24 時間）。';

    protected string $view = 'monitor.pages.logs';

    #[Url]
    public string $tab = 'all';

    #[Url]
    public ?string $site = null;

    #[Url]
    public ?string $severity = null;

    #[Url(as: 'check')]
    public ?string $checkKey = null;

    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $to = null;

    #[Url(as: 'date')]
    public ?string $businessDate = null;

    #[Url]
    public ?string $node = null;

    #[Url]
    public ?string $incident = null;

    #[Url(as: 'kind')]
    public ?string $sourceKind = null;

    #[Url(as: 'q')]
    public ?string $search = null;

    #[Url(as: 'size')]
    public int $pageSize = 20;

    #[Url]
    public ?string $cursor = null;

    #[Url]
    public bool $focus = false;

    public ?int $openId = null;

    public ?string $error = null;

    public function mount(): void
    {
        parent::mount();
        if ($this->severity === 'WARNING' && $this->tab === 'all') {
            $this->tab = 'warning';
        }
        $opts = (array) config('monitoring.ui.page_size_options', [20, 50, 100]);
        if (! in_array($this->pageSize, $opts, true)) {
            $this->pageSize = (int) config('monitoring.ui.page_size_default', 20);
        }
    }

    public function updated(string $prop): void
    {
        if (in_array($prop, ['site', 'severity', 'checkKey', 'from', 'to', 'businessDate', 'node', 'incident', 'sourceKind', 'pageSize'], true)) {
            $this->cursor = null;
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['all', 'info', 'warning', 'error'], true) ? $tab : 'all';
        $this->cursor = null;
    }

    /** 検索欄: エラー番号（MON-…）・チェック名（xxx.yyy）・ノード名の前方一致（自由文の全文検索はしない） */
    public function applySearch(): void
    {
        $q = trim((string) $this->search);
        $this->incident = null;
        if ($q !== '' && preg_match('/^MON-\d+$/i', $q)) {
            $this->incident = strtoupper($q);
        } elseif ($q !== '' && preg_match('/^[a-z_]+\.[a-z0-9_.]*$/', $q)) {
            $this->checkKey = collect($this->checkKeys())->first(fn ($k) => str_starts_with($k, $q)) ?? $q;
        } elseif ($q !== '') {
            $this->node = $q;
        }
        $this->cursor = null;
    }

    public function applyFilters(): void
    {
        $this->cursor = null;
    }

    public function resetFilters(): void
    {
        $this->reset(['site', 'severity', 'checkKey', 'from', 'to', 'businessDate', 'node', 'incident', 'sourceKind', 'search', 'cursor']);
        $this->tab = 'all';
    }

    public function goTo(?string $cursor): void
    {
        $this->cursor = $cursor ?: null;
    }

    public function refreshNewest(): void
    {
        $this->cursor = null;
    }

    public function openLog(int $id): void
    {
        $this->openId = $id;
    }

    public function closeDrawer(): void
    {
        $this->openId = null;
    }

    protected function filters(): array
    {
        $siteIds = $this->viewableSiteIds();
        if ($this->site && ($s = $this->sites()->site($this->site)) && in_array((int) $s->id, $siteIds, true)) {
            $siteIds = [(int) $s->id];
        }
        $sev = match ($this->tab) {
            'info' => ['INFO'],
            'warning' => ['WARNING'],
            'error' => ['ERROR', 'CRITICAL'],
            default => $this->severity ? [$this->severity] : [],
        };
        $tz = 'Asia/Tokyo';

        return [
            'site_ids' => $siteIds,
            'severities' => $sev,
            'check_key' => $this->checkKey ?: null,
            'from' => $this->from ? \Carbon\CarbonImmutable::parse($this->from, $tz)->utc()->toIso8601String() : null,
            'to' => $this->to ? \Carbon\CarbonImmutable::parse($this->to, $tz)->utc()->toIso8601String() : null,
            'business_date' => $this->businessDate ?: null,
            'node_code' => $this->node ?: null,
            'incident_public_id' => $this->incident ?: null,
            'source_kind' => $this->sourceKind ?: null,
        ];
    }

    public function page(): ?KeysetPage
    {
        $this->error = null;
        try {
            return app(LogQueryService::class)->page($this->filters(), $this->cursor, $this->pageSize);
        } catch (\InvalidArgumentException $e) {
            $this->error = str_starts_with($e->getMessage(), 'cursor_') ? 'ページの位置情報が無効または期限切れです。先頭から表示します。' : $e->getMessage();
            $this->cursor = null;

            return null;
        }
    }

    /** @return string[] */
    public function checkKeys(): array
    {
        return CheckModel::query()->orderBy('check_key')->pluck('check_key')->unique()->values()->all();
    }

    /** @return array<string,mixed>|null */
    public function openLogData(): ?array
    {
        if (! $this->openId) {
            return null;
        }
        $log = LogEntry::query()->whereIn('site_id', $this->viewableSiteIds())->find($this->openId);
        if (! $log) {
            return ['log' => null];
        }
        $incident = $log->incident_id ? Incident::query()->find($log->incident_id) : null;
        $runbook = null;
        if ($log->check_key) {
            $check = CheckModel::query()->where('check_key', $log->check_key)->orderByRaw('CASE WHEN site_id = ? THEN 0 ELSE 1 END', [$log->site_id])->first();
            $runbook = $check?->runbook_key ? app(RunbookRegistry::class)->latest($check->runbook_key) : null;
        }
        $copy = implode("\n", array_filter([
            '['.$this->siteLabel((int) $log->site_id).'] '.$log->severity->value.' '.self::jst($log->received_at, 'Y-m-d H:i:s').' JST',
            $log->check_key ? 'チェック: '.$log->check_key : null,
            $log->summary,
            $log->metrics_json ? '指標: '.json_encode($log->metrics_json, JSON_UNESCAPED_UNICODE) : null,
            $incident ? 'エラー番号: '.$incident->public_id : null,
        ]));

        return ['log' => $log, 'incident' => $incident, 'runbook' => $runbook, 'copy' => (string) Redactor::text($copy)];
    }

    /** CSV（表示中ページだけ・最大 100 行・伏字済み・UTF-8 BOM。V-103） */
    public function exportCsv(): StreamedResponse
    {
        $page = $this->page();
        $rows = array_slice($page?->items ?? [], 0, 100);
        $name = 'monitor-logs-'.now('Asia/Tokyo')->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['受信(JST)', '発生(JST)', 'サービス', 'ノード', '重大度', 'チェック/分類', '対象日', '要約', '判定', '件数', 'エラー番号']);
            $safe = fn ($v) => is_string($v) && $v !== '' && str_contains('=+-@\t\r', $v[0]) ? "'".$v : $v; // 表計算ソフトの式として解釈されないように
            foreach ($rows as $l) {
                fputcsv($out, array_map($safe, [
                    self::jst($l->received_at, 'Y-m-d H:i:s'), self::jst($l->occurred_at, 'Y-m-d H:i:s'), $this->siteLabel((int) $l->site_id), $l->node_code,
                    $l->severity->value, $l->check_key ?: \App\Monitoring\Support\Ui::label($l->source_kind), $l->business_date?->format('Y-m-d'),
                    Redactor::text((string) $l->summary), $l->outcome?->value, (int) $l->occurrence_delta,
                    $l->incident_id ? \App\Monitoring\Support\PublicId::incident((int) $l->incident_id) : '',
                ]));
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
