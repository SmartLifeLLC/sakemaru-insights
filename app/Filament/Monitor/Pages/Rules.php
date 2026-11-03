<?php

namespace App\Filament\Monitor\Pages;

use App\Filament\Monitor\MonitorPage;
use App\Models\Monitoring\AuditEvent;
use App\Models\Monitoring\Check as CheckModel;
use App\Models\Monitoring\Feature;
use App\Models\Monitoring\Node;
use App\Models\Monitoring\Site;
use App\Models\Monitoring\Target;
use App\Monitoring\Resources\ThresholdEvaluator;
use App\Monitoring\Services\CheckRegistry;
use App\Monitoring\Services\FeatureRegistry;
use App\Monitoring\Services\MonitorAccess;
use App\Monitoring\Services\RuleService;
use App\Monitoring\Services\SourceReader;
use App\Monitoring\Services\TargetService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Livewire\Attributes\Url;

/**
 * 監視ルール（v2 第4.3 / V-110〜115）。サービス → 機能 → 監視項目の 3 段 ON/OFF、ルール編集、判定テスト、ノードと対象、変更履歴。
 * 閲覧は monitor.view、変更は monitor.manage のみ（サーバー側で確認）。変更理由は必須で監査に残る。
 */
class Rules extends MonitorPage
{
    protected static ?string $title = '監視ルール';

    protected static ?string $slug = 'rules';

    protected static string $navKey = 'rules';

    protected static string $description = '顧客ごとに使う機能だけを監視します。判定条件と通知動作を、優先順位に沿って管理します。';

    protected string $view = 'monitor.pages.rules';

    #[Url]
    public string $tab = 'features';

    // 判定テスト（業務DB を叩かない）
    public string $testMetric = 'cpu_pct';

    public string $testValues = '95, 96, 97, 95, 99, 98, 96, 97, 95, 96, 99';

    public ?string $testWarning = null;

    public ?string $testError = null;

    public ?string $testDuration = null;

    public ?array $testResult = null;

    public function setTab(string $t): void
    {
        $this->tab = in_array($t, ['features', 'rules', 'test', 'nodes', 'history'], true) ? $t : 'features';
    }

    public function canManage(): bool
    {
        return $this->can(MonitorAccess::MANAGE);
    }

    private function guardManage(): void
    {
        $this->access()->authorize(auth()->user(), MonitorAccess::MANAGE);
    }

    private function reasonField(): Textarea
    {
        return Textarea::make('reason')->label('変更理由（必須・監査に保存）')->rows(2)->required()->minLength(2);
    }

    private function run(callable $fn, string $ok): void
    {
        try {
            $this->guardManage();
            $fn();
            app(FeatureRegistry::class)->forget();
            $this->ok($ok);
        } catch (\App\Monitoring\Exceptions\AccessDenied) {
            $this->fail('変更の権限がありません（monitor.manage）。');
        } catch (\InvalidArgumentException|\App\Monitoring\Exceptions\InvalidTransition|\App\Monitoring\Exceptions\VersionConflict $e) {
            $this->fail($e->getMessage());
        } catch (\Throwable $e) {
            report($e); // SQL 等の内部情報は画面に出さない
            $this->fail('保存できませんでした（内部エラー: '.class_basename($e).'）。管理者に連絡してください。');
        }
    }

    /** @return array<string,mixed> */
    public function data(): array
    {
        $reg = app(FeatureRegistry::class);
        $sites = Site::query()->orderBy('sort_order')->get();
        $features = Feature::query()->orderBy('sort_order')->get()->groupBy('site_id');
        $checks = CheckModel::query()->orderBy('priority')->orderBy('id')->get();
        $byFeature = $checks->groupBy(fn ($c) => $c->site_id.':'.($c->feature_key ?: $reg->featureForCheck($c->check_key)));
        $tree = [];
        foreach ($sites as $s) {
            $fs = [];
            foreach ($features->get($s->id, collect()) as $f) {
                $cs = $byFeature->get($s->id.':'.$f->feature_key, collect());
                // サービス共通の「HTTP 死活」は INSIGHTS の http.probe が対象を巡回する
                $fs[] = ['feature' => $f, 'checks' => $cs->map(fn ($c) => ['model' => $c, 'gate' => $reg->gate($c)])->all()];
            }
            $tree[] = ['site' => $s, 'features' => $fs];
        }
        $registry = app(CheckRegistry::class);

        return [
            'tree' => $tree,
            'checks' => $checks->map(function ($c) use ($registry, $reg) {
                $site = $this->sites()->siteById((int) $c->site_id);
                $def = $site ? ($registry->definition($site->application_code, $c->check_key) ?? $registry->definitionByKey($c->check_key)) : null;

                return ['model' => $c, 'site' => $site, 'def' => $def, 'gate' => $reg->gate($c)];
            })->groupBy(fn ($x) => $x['model']->site_id),
            'nodes' => Node::query()->orderBy('node_code')->get(),
            'probes' => Target::query()->where('kind', 'HTTP_PROBE')->orderBy('site_id')->get(),
            'collectors' => (array) config('monitoring.collectors', []),
            'audit' => $this->tab === 'history' ? AuditEvent::query()->orderByDesc('id')->limit(100)->get() : collect(),
            'canManage' => $this->canManage(),
        ];
    }

    // ------------------------------------------------------------------ 3 段 ON/OFF

    public function toggleSiteAction(): Action
    {
        // ボタン自体が現在の状態（ON=緑 / OFF=灰）。押すと理由を入れて反対側へ切り替える（WMS の ON バッジと同じ見え方）
        return Action::make('toggleSite')->size('sm')
            ->label(fn (array $arguments) => Site::query()->find($arguments['id'] ?? 0)?->enabled ? 'ON' : 'OFF')
            ->color(fn (array $arguments) => Site::query()->find($arguments['id'] ?? 0)?->enabled ? 'success' : 'gray')
            ->extraAttributes(fn (array $arguments) => ['class' => 'onoff '.(Site::query()->find($arguments['id'] ?? 0)?->enabled ? 'is-on' : 'is-off'), 'title' => '押すと切り替え（理由を入力）'])
            ->modalHeading(fn (array $arguments) => 'サービスの監視を'.(Site::query()->find($arguments['id'] ?? 0)?->enabled ? '停止' : '再開').'する')
            ->modalDescription('停止中は配下の機能・監視項目を実行せず、画面では「未監視（サービス停止）」と表示します。')
            ->modalSubmitActionLabel('切り替える')->modalCancelActionLabel('切り替えず閉じる')->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
            ->visible(fn () => $this->canManage())
            ->schema([$this->reasonField()])
            ->action(function (array $arguments, array $data) {
                $s = Site::query()->findOrFail($arguments['id'] ?? 0);
                $this->run(fn () => app(FeatureRegistry::class)->setSiteEnabled($s, ! $s->enabled, $this->actor(), (string) $data['reason']), $this->sites()->label($s->application_code).' を'.($s->enabled ? '停止' : '再開').'しました。');
                $this->sites()->forget();
            });
    }

    public function toggleFeatureAction(): Action
    {
        return Action::make('toggleFeature')->size('sm')
            ->label(fn (array $arguments) => Feature::query()->find($arguments['id'] ?? 0)?->enabled ? 'ON' : 'OFF')
            ->color(fn (array $arguments) => Feature::query()->find($arguments['id'] ?? 0)?->enabled ? 'success' : 'gray')
            ->extraAttributes(fn (array $arguments) => ['class' => 'onoff '.(Feature::query()->find($arguments['id'] ?? 0)?->enabled ? 'is-on' : 'is-off'), 'title' => '押すと切り替え（理由を入力）'])
            ->modalHeading(fn (array $arguments) => '機能「'.(Feature::query()->find($arguments['id'] ?? 0)?->display_name).'」を'.(Feature::query()->find($arguments['id'] ?? 0)?->enabled ? '未監視にする' : '監視する'))
            ->modalDescription(fn (array $arguments) => ($f = Feature::query()->find($arguments['id'] ?? 0)) && $f->recommended_enabled !== null ? '推奨: '.($f->recommended_enabled ? '有効' : '無効').'（'.$f->recommended_reason.'）' : '推奨値は未検出です。')
            ->modalSubmitActionLabel('切り替える')->modalCancelActionLabel('切り替えず閉じる')->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
            ->visible(fn () => $this->canManage())
            ->schema([$this->reasonField()])
            ->action(function (array $arguments, array $data) {
                $f = Feature::query()->findOrFail($arguments['id'] ?? 0);
                $this->run(fn () => app(FeatureRegistry::class)->setEnabled($f, ! $f->enabled, $this->actor(), (string) $data['reason']), '「'.$f->display_name.'」を'.($f->enabled ? '未監視' : '監視中').'にしました。');
            });
    }

    public function toggleCheckAction(): Action
    {
        return Action::make('toggleCheck')->size('sm')
            ->label(fn (array $arguments) => CheckModel::query()->find($arguments['id'] ?? 0)?->enabled ? 'ON' : 'OFF')
            ->color(fn (array $arguments) => CheckModel::query()->find($arguments['id'] ?? 0)?->enabled ? 'success' : 'gray')
            ->extraAttributes(fn (array $arguments) => ['class' => 'onoff '.(CheckModel::query()->find($arguments['id'] ?? 0)?->enabled ? 'is-on' : 'is-off'), 'title' => '押すと切り替え（理由を入力）'])
            ->modalHeading(fn (array $arguments) => '監視項目 '.(CheckModel::query()->find($arguments['id'] ?? 0)?->check_key).' を切り替える')
            ->modalSubmitActionLabel('切り替える')->modalCancelActionLabel('切り替えず閉じる')->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
            ->visible(fn () => $this->canManage())
            ->schema([$this->reasonField()])
            ->action(function (array $arguments, array $data) {
                $c = CheckModel::query()->findOrFail($arguments['id'] ?? 0);
                $this->run(fn () => app(FeatureRegistry::class)->setCheckEnabled($c, ! $c->enabled, $this->actor(), (string) $data['reason']), $c->check_key.' を'.($c->enabled ? '停止' : '有効化').'しました。');
            });
    }

    public function detectAction(): Action
    {
        return Action::make('detect')->label('推奨値を検出')->color('gray')->icon('heroicon-o-magnifying-glass')
            ->modalHeading('機能の使用状況から推奨値を検出する')->modalDescription('業務DB に軽い SELECT（1 秒上限）を実行し、推奨値だけを更新します。設定は変えません。')
            ->modalSubmitActionLabel('検出する')->modalCancelActionLabel('検出せず閉じる')->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
            ->visible(fn () => $this->canManage())
            ->action(function () {
                $this->run(function () {
                    $r = app(FeatureRegistry::class)->detect(SourceReader::fromConfig());
                    $this->flash = '推奨値を更新しました（'.count($r).' 機能）。「要確認」バッジの機能を確認してください。';
                }, '推奨値を更新しました。');
            });
    }

    // ------------------------------------------------------------------ ルール編集

    public function editRuleAction(): Action
    {
        return Action::make('editRule')->label('編集')->size('sm')->color('gray')
            ->modalHeading(fn (array $arguments) => 'ルール: '.(CheckModel::query()->find($arguments['id'] ?? 0)?->display_name))
            ->modalSubmitActionLabel('保存する')->modalCancelActionLabel('保存せず閉じる')->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])->modalWidth('2xl')
            ->visible(fn () => $this->canManage())
            ->fillForm(function (array $arguments) {
                $c = CheckModel::query()->find($arguments['id'] ?? 0);

                return $c ? ['enabled' => (bool) $c->enabled, 'interval_seconds' => (int) $c->interval_seconds, 'grace_seconds' => (int) $c->grace_seconds,
                    'severity' => $c->rule_json['severity'] ?? '', 'action' => $c->rule_json['action'] ?? 'notify',
                    'config' => json_encode($c->config_json ?: new \stdClass, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 'reason' => ''] : [];
            })
            ->schema(fn (array $arguments) => [
                Grid::make(2)->schema([
                    Toggle::make('enabled')->label('有効'),
                    Select::make('action')->label('一致したときの動作')->options(['notify' => '通知する', 'record_only' => '記録のみ（エラー管理・通知へ上げない）'])->required(),
                    TextInput::make('interval_seconds')->label('実行間隔（秒）')->numeric()->minValue(60)->maxValue(86400)->required(),
                    TextInput::make('grace_seconds')->label('猶予（秒）')->numeric()->minValue(0)->maxValue(86400)->required(),
                    Select::make('severity')->label('違反時の重大度')->options(['' => '既定（チェックの判定どおり）', 'WARNING' => '警告（通知しない）', 'ERROR' => 'エラー', 'CRITICAL' => '重大'])->default(''),
                ]),
                Textarea::make('config')->label('しきい値・比較条件（JSON。編集可能なキー: '.implode(', ', $this->editableKeys((int) ($arguments['id'] ?? 0))).'）')->rows(6),
                $this->reasonField(),
            ])
            ->action(function (array $arguments, array $data) {
                $c = CheckModel::query()->findOrFail($arguments['id'] ?? 0);
                $cfg = json_decode((string) ($data['config'] ?? '{}'), true);
                if (! is_array($cfg)) {
                    $this->fail('設定 JSON が不正です。');

                    return;
                }
                $this->run(fn () => app(RuleService::class)->update($c, ['enabled' => (bool) $data['enabled'], 'interval_seconds' => (int) $data['interval_seconds'], 'grace_seconds' => (int) $data['grace_seconds'],
                    'severity' => $data['severity'] ?: null, 'action' => $data['action'], 'config' => $cfg], $this->actor(), (string) $data['reason']), $c->check_key.' のルールを保存しました。');
            });
    }

    /** @return string[] */
    private function editableKeys(int $checkId): array
    {
        $c = CheckModel::query()->find($checkId);
        $site = $c ? $this->sites()->siteById((int) $c->site_id) : null;
        $def = $site ? (app(CheckRegistry::class)->definition($site->application_code, $c->check_key) ?? app(CheckRegistry::class)->definitionByKey($c->check_key)) : null;

        return $def?->editableConfigKeys ?: ['（なし）'];
    }

    public function moveCheck(int $id, string $dir): void
    {
        $c = CheckModel::query()->find($id);
        if (! $c) {
            return;
        }
        $this->run(fn () => app(RuleService::class)->move($c, $dir === 'up' ? 'up' : 'down', $this->actor()), '優先順位を変更しました。');
    }

    // ------------------------------------------------------------------ 判定テスト

    public function runTest(): void
    {
        $vals = array_values(array_filter(array_map('trim', explode(',', $this->testValues)), fn ($v) => $v !== ''));
        if (! $vals || array_filter($vals, fn ($v) => ! is_numeric($v))) {
            $this->testResult = ['error' => '値はカンマ区切りの数値で入力してください（30 秒ごと、古い順）。'];

            return;
        }
        $vals = array_map('floatval', array_slice($vals, -40));
        $now = 1_800_000_000;
        $t = [];
        foreach ($vals as $i => $_) {
            $t[] = $now - (count($vals) - 1 - $i) * 30;
        }
        $key = ['cpu_pct' => 'cpu', 'iowait_pct' => 'iowait', 'load_per_core' => 'load1', 'mem_available_pct' => 'mem_available_pct', 'swap_used_pct' => 'swap_used_pct'][$this->testMetric] ?? null;
        $th = ThresholdEvaluator::resolve(null);
        if (! isset($th[$this->testMetric]) || ! $key) {
            $this->testResult = ['error' => '指標が不正です。'];

            return;
        }
        foreach (['warning' => $this->testWarning, 'error' => $this->testError, 'duration' => $this->testDuration] as $k => $v) {
            if ($v !== null && $v !== '' && is_numeric($v)) {
                $th[$this->testMetric][$k] = (float) $v;
            }
        }
        $only = [$this->testMetric => $th[$this->testMetric]];
        $latest = ['ts' => $now, 'cores' => 1, 'current' => [$key => end($vals)], 'recent' => ['t' => $t, $key => $vals], 'disks' => []];
        $r = ThresholdEvaluator::evaluate($latest, $only, $now)[$this->testMetric] ?? null;
        $this->testResult = ['level' => $r['level'] ?? 'ok', 'threshold' => $r['threshold'] ?? null, 'duration' => (int) ($th[$this->testMetric]['duration'] ?? 0), 'points' => count($vals), 'span' => (count($vals) - 1) * 30];
    }

    // ------------------------------------------------------------------ ノードと対象

    public function editNodeAction(): Action
    {
        $metrics = array_keys((array) config('monitoring.resources.thresholds', []));

        return Action::make('editNode')->label('編集')->size('sm')->color('gray')
            ->modalHeading(fn (array $arguments) => 'ノード '.(Node::query()->find($arguments['id'] ?? 0)?->node_code))
            ->modalDescription('ノードコードは各サーバーの .env（MONITOR_NODE_CODE）で固定です。ここでは表示名・有効/無効・しきい値だけを変更できます。空欄は既定値を使います。')
            ->modalSubmitActionLabel('保存する')->modalCancelActionLabel('保存せず閉じる')->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])->modalWidth('3xl')
            ->visible(fn () => $this->canManage())
            ->fillForm(function (array $arguments) {
                $n = Node::query()->find($arguments['id'] ?? 0);

                return $n ? ['display_name' => $n->display_name, 'enabled' => (bool) $n->enabled, 'th' => (array) $n->thresholds_json, 'reason' => ''] : [];
            })
            ->schema(array_merge([
                Grid::make(2)->schema([TextInput::make('display_name')->label('表示名')->required()->maxLength(120), Toggle::make('enabled')->label('有効')]),
            ], array_map(function ($m) {
                $d = (array) config('monitoring.resources.thresholds.'.$m);

                return Section::make(ThresholdEvaluator::LABELS[$m] ?? $m)->description('既定: 警告 '.($d['warning'] ?? '—').' / エラー '.($d['error'] ?? '—').(isset($d['critical']) ? ' / 重大 '.$d['critical'] : '').' / 継続 '.(($d['duration'] ?? 0) ? intdiv((int) $d['duration'], 60).' 分' : '即時'))
                    ->compact()->columns(4)->schema([
                        TextInput::make("th.{$m}.warning")->label('警告')->numeric(),
                        TextInput::make("th.{$m}.error")->label('エラー')->numeric(),
                        TextInput::make("th.{$m}.duration")->label('継続（秒）')->numeric(),
                        Toggle::make("th.{$m}.disabled")->label('判定しない'),
                    ]);
            }, $metrics), [$this->reasonField()]))
            ->action(function (array $arguments, array $data) {
                $n = Node::query()->findOrFail($arguments['id'] ?? 0);
                $this->run(fn () => app(RuleService::class)->updateNode($n, ['display_name' => $data['display_name'], 'enabled' => (bool) $data['enabled'], 'thresholds' => (array) ($data['th'] ?? [])], $this->actor(), (string) $data['reason']), 'ノード '.$n->node_code.' を保存しました。');
            });
    }

    public function addProbeAction(): Action
    {
        return Action::make('addProbe')->label('HTTP プローブを追加')->color('primary')->icon('heroicon-o-plus')
            ->modalHeading('HTTP プローブを登録する')->modalDescription('顧客の自サービスのヘルスチェック URL（/up）を 60 秒ごとに確認します。https のみ・許可ホストのみ（MONITOR_PROBE_ALLOWED_HOSTS）。リダイレクトは追いません。')
            ->modalSubmitActionLabel('登録する')->modalCancelActionLabel('登録せず閉じる')->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
            ->visible(fn () => $this->canManage())
            ->schema([
                Select::make('site')->label('サービス')->required()->options(fn () => collect($this->siteOptions())->reject(fn ($s) => $s['code'] === 'INSIGHTS')->mapWithKeys(fn ($s) => [$s['code'] => $s['label']])->all()),
                TextInput::make('display_name')->label('表示名')->default('/up')->maxLength(120),
                TextInput::make('url')->label('URL')->placeholder('https://wms.example.jp/up')->required(),
                TextInput::make('expect_status')->label('期待するステータス')->numeric()->default(200)->required(),
                $this->reasonField(),
            ])
            ->action(function (array $data) {
                $this->run(fn () => app(TargetService::class)->createProbe((string) $data['site'], (string) $data['display_name'], (string) $data['url'], $this->actor(), (string) $data['reason'], (int) $data['expect_status']), 'HTTP プローブを登録しました。');
            });
    }

    public function editProbeAction(): Action
    {
        return Action::make('editProbe')->label('編集')->size('sm')->color('gray')
            ->modalHeading('HTTP プローブを編集する')->modalSubmitActionLabel('保存する')->modalCancelActionLabel('保存せず閉じる')->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
            ->visible(fn () => $this->canManage())
            ->fillForm(fn (array $arguments) => ($t = Target::query()->find($arguments['id'] ?? 0)) ? ['display_name' => $t->display_name, 'url' => $t->params_json['url'] ?? '', 'expect_status' => $t->params_json['expect_status'] ?? 200, 'enabled' => (bool) $t->enabled, 'reason' => ''] : [])
            ->schema([
                TextInput::make('display_name')->label('表示名')->required()->maxLength(120),
                TextInput::make('url')->label('URL')->required(),
                TextInput::make('expect_status')->label('期待するステータス')->numeric()->required(),
                Toggle::make('enabled')->label('有効'),
                $this->reasonField(),
            ])
            ->action(function (array $arguments, array $data) {
                $t = Target::query()->findOrFail($arguments['id'] ?? 0);
                $this->run(function () use ($t, $data) {
                    $svc = app(TargetService::class);
                    $svc->update($t, ['display_name' => $data['display_name'], 'url' => $data['url'], 'expect_status' => (int) $data['expect_status']], $this->actor(), (string) $data['reason']);
                    if ((bool) $data['enabled'] !== (bool) $t->enabled) {
                        $svc->setEnabled($t->fresh(), (bool) $data['enabled'], $this->actor(), (string) $data['reason']);
                    }
                }, 'HTTP プローブを保存しました。');
            });
    }

    public function deleteNodeAction(): Action
    {
        return Action::make('deleteNode')->label('削除')->size('sm')->color('danger')->requiresConfirmation()
            ->modalHeading(fn (array $arguments) => 'ノード登録 '.(Node::query()->find($arguments['id'] ?? 0)?->node_code).' を削除する')
            ->modalDescription('名前変更前の残骸など、もう使わない登録を消します。資源データが 10 分以上届いていないノードだけ削除できます。このノードの未解決インシデントは終了し、保留中の通知は取り消されます。')
            ->modalSubmitActionLabel('削除する')->modalCancelActionLabel('削除せず閉じる')->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
            ->visible(fn () => $this->canManage())
            ->schema([$this->reasonField()])
            ->action(function (array $arguments, array $data) {
                $n = Node::query()->findOrFail($arguments['id'] ?? 0);
                $this->run(fn () => app(RuleService::class)->deleteNode($n, $this->actor(), (string) $data['reason']), 'ノード登録 '.$n->node_code.' を削除しました。');
            });
    }
}
