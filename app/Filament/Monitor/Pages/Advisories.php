<?php

namespace App\Filament\Monitor\Pages;

use App\Filament\Monitor\MonitorPage;
use App\Models\Monitoring\Advisory;
use App\Models\Monitoring\AdvisoryFetch;
use App\Monitoring\Advisories\AdvisoryService;
use App\Monitoring\Services\MonitorAccess;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Livewire\Attributes\Url;

/**
 * 脆弱性情報（外部情報源から収集した公開アドバイザリ）。新規 → 確認済み / 対象外 / 対応済み の状態管理。
 * 情報源・取得頻度は docs/monitoring/security-advisories.md。
 */
class Advisories extends MonitorPage
{
    protected static ?string $title = '脆弱性情報';

    protected static ?string $slug = 'advisories';

    protected static string $navKey = 'advisories';

    protected static string $description = 'NVD・GitHub Advisory・Ubuntu USN・CISA KEV・AWS・JVN から収集した、Apache / PHP / Laravel / Livewire / Ubuntu / OpenSSL / MySQL / AWS の脆弱性情報です。';

    protected string $view = 'monitor.pages.advisories';

    public const TABS = ['new' => '新規', 'urgent' => '要対応', 'acknowledged' => '確認済み', 'not_applicable' => '対象外', 'resolved' => '対応済み', 'all' => 'すべて'];

    #[Url]
    public string $tab = 'new';

    #[Url]
    public string $source = '';

    #[Url]
    public string $severity = '';

    #[Url]
    public string $component = '';

    #[Url]
    public string $q = '';

    #[Url]
    public ?int $open = null;

    public int $limit = 100;

    public function setTab(string $tab): void
    {
        $this->tab = isset(self::TABS[$tab]) ? $tab : 'new';
        $this->limit = 100;
    }

    public function openRow(int $id): void
    {
        $this->open = $id;
    }

    public function closeDrawer(): void
    {
        $this->open = null;
    }

    public function more(): void
    {
        $this->limit += 100;
    }

    public function resetFilters(): void
    {
        $this->source = $this->severity = $this->component = $this->q = '';
    }

    public function canRespond(): bool
    {
        return $this->can(MonitorAccess::RESPOND);
    }

    /** @return array<string,mixed> */
    public function data(): array
    {
        $base = Advisory::query();
        $counts = [
            'new' => (clone $base)->where('status', 'NEW')->count(),
            'urgent' => (clone $base)->where('status', 'NEW')->where(fn ($q) => $q->where('severity', 'CRITICAL')->orWhere('kev', true)->orWhere('installed_affected', true))->count(),
            'acknowledged' => (clone $base)->where('status', 'ACKNOWLEDGED')->count(),
            'not_applicable' => (clone $base)->where('status', 'NOT_APPLICABLE')->count(),
            'resolved' => (clone $base)->where('status', 'RESOLVED')->count(),
            'all' => (clone $base)->count(),
        ];
        $q = Advisory::query();
        match ($this->tab) {
            'new' => $q->where('status', 'NEW'),
            'urgent' => $q->where('status', 'NEW')->where(fn ($w) => $w->where('severity', 'CRITICAL')->orWhere('kev', true)->orWhere('installed_affected', true)),
            'acknowledged' => $q->where('status', 'ACKNOWLEDGED'),
            'not_applicable' => $q->where('status', 'NOT_APPLICABLE'),
            'resolved' => $q->where('status', 'RESOLVED'),
            default => null,
        };
        if ($this->source !== '') {
            $q->where('source', $this->source);
        }
        if ($this->severity !== '') {
            $q->where('severity', $this->severity);
        }
        if ($this->component !== '') {
            $q->where('components_json', 'like', '%"'.addcslashes($this->component, '%_').'"%');
        }
        if (trim($this->q) !== '') {
            $term = '%'.addcslashes(trim($this->q), '%_').'%';
            $q->where(fn ($w) => $w->where('external_id', 'like', $term)->orWhere('cve_id', 'like', $term)->orWhere('title', 'like', $term));
        }
        $rows = $q->orderByRaw("CASE severity WHEN 'CRITICAL' THEN 0 WHEN 'HIGH' THEN 1 WHEN 'MEDIUM' THEN 2 WHEN 'LOW' THEN 3 ELSE 4 END")->orderByDesc('kev')->orderByDesc('installed_affected')->orderByDesc('published_at')->orderByDesc('id')->limit($this->limit + 1)->get();
        $hasMore = $rows->count() > $this->limit;
        $fetches = AdvisoryFetch::query()->orderByDesc('id')->limit(120)->get()->groupBy('source')->map(fn ($g) => $g->first());
        $products = (array) config('monitoring.advisories.products');

        return [
            'counts' => $counts, 'rows' => $rows->take($this->limit), 'has_more' => $hasMore,
            'current' => $this->open ? Advisory::query()->find($this->open) : null,
            'fetches' => $fetches, 'headline' => app(AdvisoryService::class)->headline(),
            'products' => array_map(fn ($p) => $p['label'], $products) + ['composer' => 'Composer パッケージ'],
        ];
    }

    public function statusAction(): Action
    {
        return Action::make('status')->label('状態を変更')->size('sm')
            ->visible(fn () => $this->canRespond())
            ->modalHeading(fn (array $arguments) => '脆弱性情報の状態を変更: '.(Advisory::query()->find($arguments['id'] ?? 0)?->external_id ?? ''))
            ->modalSubmitActionLabel('記録する')->modalCancelActionLabel('記録せず閉じる')->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
            ->fillForm(fn (array $arguments) => ['status' => Advisory::query()->find($arguments['id'] ?? 0)?->status === 'NEW' ? 'ACKNOWLEDGED' : (Advisory::query()->find($arguments['id'] ?? 0)?->status ?? 'ACKNOWLEDGED')])
            ->schema([
                Select::make('status')->label('状態')->options(Advisory::STATUSES)->required()->helperText('対象外・対応済みには理由が必要です（監査に残ります）。'),
                Textarea::make('note')->label('理由・メモ')->rows(3)->maxLength(1000)->placeholder('例: 本番は PHP 8.4.12 で修正済みの版 / 該当モジュールは使用していない / 11/10 のメンテで更新済み'),
            ])
            ->action(function (array $arguments, array $data) {
                try {
                    $this->access()->authorize(auth()->user(), MonitorAccess::RESPOND);
                    $a = Advisory::query()->findOrFail($arguments['id']);
                    app(AdvisoryService::class)->setStatus($a, (string) $data['status'], $this->actor(), $data['note'] ?? null);
                    $this->ok($a->external_id.' を「'.Advisory::STATUSES[$data['status']].'」にしました。');
                } catch (\InvalidArgumentException $e) {
                    $this->fail($e->getMessage());
                } catch (\App\Monitoring\Exceptions\AccessDenied) {
                    $this->fail('この操作の権限がありません。');
                }
            });
    }

    /** 一覧行の「確認済みにする」（理由不要） */
    public function acknowledge(int $id): void
    {
        try {
            $this->access()->authorize(auth()->user(), MonitorAccess::RESPOND);
            $a = Advisory::query()->findOrFail($id);
            app(AdvisoryService::class)->setStatus($a, 'ACKNOWLEDGED', $this->actor(), null);
            $this->ok($a->external_id.' を確認済みにしました。');
        } catch (\App\Monitoring\Exceptions\AccessDenied) {
            $this->fail('この操作の権限がありません。');
        }
    }
}
