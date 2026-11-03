<?php

namespace App\Filament\Monitor\Pages;

use App\Filament\Monitor\MonitorPage;
use App\Models\Monitoring\Check as CheckModel;
use App\Models\Monitoring\Runbook;
use App\Monitoring\Services\RunbookRegistry;
use Livewire\Attributes\Url;

/** 対処手順（v2 V-105）。カード一覧 → ドロワーで全文。手順中のコマンドは説明用で画面から実行しない。 */
class Runbooks extends MonitorPage
{
    protected static ?string $title = '対処手順';

    protected static ?string $slug = 'runbooks';

    protected static string $navKey = 'runbooks';

    protected static string $description = '検知した問題から、次に行う作業を確認します。手順中のコマンドは説明用で、この画面からは実行しません。';

    protected string $view = 'monitor.pages.runbooks';

    #[Url]
    public ?string $key = null;

    public function mount(): void
    {
        parent::mount();
        if (Runbook::query()->where('version', RunbookRegistry::VERSION)->count() < count(RunbookRegistry::definitions())) {
            app(RunbookRegistry::class)->sync();
        }
    }

    public function select(string $key): void
    {
        $this->key = $key;
    }

    public function closeDrawer(): void
    {
        $this->key = null;
    }

    /** @return array<string,mixed> */
    public function data(): array
    {
        $list = Runbook::query()->where('enabled', true)->orderBy('runbook_key')->orderByDesc('version')->get()->unique('runbook_key')->values();
        $checks = CheckModel::query()->whereNotNull('runbook_key')->get(['check_key', 'runbook_key'])->groupBy('runbook_key')->map(fn ($g) => $g->pluck('check_key')->unique()->values()->all());

        return ['list' => $list, 'current' => $this->key ? $list->firstWhere('runbook_key', $this->key) : null, 'checks' => $checks];
    }
}
