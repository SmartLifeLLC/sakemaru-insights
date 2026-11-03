<?php

namespace App\Filament\Monitor\Pages;

use App\Filament\Monitor\MonitorPage;
use App\Models\Monitoring\Check as CheckModel;
use App\Models\Monitoring\Incident;
use App\Models\Monitoring\IncidentEvent;
use App\Models\Monitoring\LogEntry;
use App\Models\Monitoring\Notification;
use App\Models\Monitoring\NotificationAttempt;
use App\Models\Monitoring\RecheckRequest;
use App\Monitoring\Exceptions\AccessDenied;
use App\Monitoring\Exceptions\InvalidTransition;
use App\Monitoring\Exceptions\VersionConflict;
use App\Monitoring\Services\IncidentService;
use App\Monitoring\Services\MonitorAccess;
use App\Monitoring\Services\RecheckService;
use App\Monitoring\Services\RunbookRegistry;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Panel;
use Illuminate\Support\Facades\DB;

/** エラー詳細（仕様書 11.4）。上部に「何が異常か・影響範囲・確認済みか・誰が対応中か・Slackに届いたか」。 */
class IncidentDetail extends MonitorPage
{
    protected static ?string $title = 'エラー詳細';

    protected static ?string $slug = 'incidents/detail';

    protected static string $navKey = 'incidents';

    protected static string $description = '何が異常か・影響範囲・確認済みか・誰が対応中か・通知が届いたか。';

    protected string $view = 'monitor.pages.incident-detail';

    public string $publicId = '';

    public static function getRoutePath(Panel $panel): string
    {
        return '/incidents/{publicId}';
    }

    public function mount(string $publicId = ''): void
    {
        parent::mount();
        $this->publicId = strtoupper($publicId);
        $incident = $this->incident();
        abort_unless($incident, 404);
        abort_unless($this->can(MonitorAccess::VIEW, $this->siteCode((int) $incident->site_id)), 403);
    }

    public function incident(): ?Incident
    {
        return Incident::query()->where('public_id', $this->publicId)->first();
    }

    /** @return array<string,mixed> */
    public function data(): array
    {
        $i = $this->incident();
        $pageSize = (int) config('monitoring.ui.detail_history_page_size', 50);

        return [
            'incident' => $i,
            'site' => $this->sites()->siteById((int) $i->site_id),
            'events' => IncidentEvent::query()->where('incident_id', $i->id)->orderByDesc('id')->limit($pageSize)->get(),
            'notifications' => Notification::query()->where('incident_id', $i->id)->orderByDesc('id')->limit($pageSize)->get(),
            'calls' => \App\Models\Monitoring\CallAttempt::query()->whereIn('notification_id', Notification::query()->where('incident_id', $i->id)->where('route_key', 'phone')->pluck('id'))->orderByDesc('id')->limit(30)->get(),
            'contacts' => \App\Models\Monitoring\Contact::query()->pluck('name', 'id')->all(),
            'attempts' => NotificationAttempt::query()->whereIn('notification_id', Notification::query()->where('incident_id', $i->id)->orderByDesc('id')->limit($pageSize)->pluck('id'))->orderByDesc('id')->limit($pageSize)->get()->groupBy('notification_id'),
            'logs' => LogEntry::query()->select(\App\Monitoring\Services\LogQueryService::COLUMNS)->where('incident_id', $i->id)->orderByDesc('id')->limit(20)->get(),
            'runbook' => $i->runbook_key ? app(RunbookRegistry::class)->latest($i->runbook_key) : null,
            'check' => $i->check_id ? CheckModel::query()->find($i->check_id) : null,
            'rechecks' => RecheckRequest::query()->where('incident_id', $i->id)->orderByDesc('id')->limit(10)->get(),
            'canRespond' => $this->can(MonitorAccess::RESPOND, $this->siteCode((int) $i->site_id)),
            'now' => \Carbon\CarbonImmutable::now('UTC'),
        ];
    }

    // ------------------------------------------------------------------
    // 操作（楽観ロック・冪等キー・監査）
    // ------------------------------------------------------------------

    private function perform(string $action, array $input = []): void
    {
        $incident = $this->incident();
        $actor = $this->access()->actor(auth()->user());
        try {
            $this->access()->authorize(auth()->user(), MonitorAccess::RESPOND, $this->siteCode((int) $incident->site_id));
            $key = "ui:{$incident->id}:{$action}:{$incident->version}:".substr(hash('sha256', json_encode($input)), 0, 8);
            DB::connection('monitor_store')->transaction(fn () => app(IncidentService::class)->act($incident, $action, (int) $incident->version, $actor, $input, $key), 2);
            $this->flash = match ($action) {
                'acknowledge' => '確認を記録しました。データが正常になったことを意味しません。',
                'assign' => '担当を割り当てました。',
                'start' => '対応を開始しました。',
                'note' => 'メモを記録しました。',
                'snooze' => '通知を抑止しました。期限後もエラーが継続していれば再通知されます。',
                'unsnooze' => '抑止を解除しました。',
                'mark_recovered' => '復旧確認を記録しました。',
                'resolve' => '解決として記録しました。最終確認後に「終了」してください。',
                'close' => '終了しました。',
                'reopen' => '再オープンしました。',
                default => '記録しました。',
            };
            $this->flashLevel = 'ok';
        } catch (VersionConflict $e) {
            $this->flash = $e->getMessage();
            $this->flashLevel = 'error';
        } catch (InvalidTransition|AccessDenied $e) {
            $this->flash = $e->getMessage();
            $this->flashLevel = 'error';
        }
    }

    public function acknowledgeAction(): Action
    {
        return Action::make('acknowledge')->label('確認')->color('primary')
            ->modalHeading('エラーを確認済みにする')->modalDescription('内容を把握したことを記録します。データが正常になったとは表示されません。')
            ->modalSubmitActionLabel('確認を記録')->modalCancelActionLabel('記録せず閉じる')->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
            ->schema([Textarea::make('reason')->label('コメント（任意）')->rows(3)])
            ->action(fn (array $data) => $this->perform('acknowledge', $data));
    }

    public function assignAction(): Action
    {
        return Action::make('assign')->label('担当割当')->color('gray')
            ->modalHeading('担当者を割り当てる')->modalSubmitActionLabel('割り当てる')->modalCancelActionLabel('割り当てず閉じる')->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
            ->schema([TextInput::make('assignee_name')->label('担当者名')->default(fn () => auth()->user()?->name)->required()->maxLength(100)])
            ->action(fn (array $data) => $this->perform('assign', ['assignee_name' => $data['assignee_name'], 'assignee_id' => $data['assignee_name'] === auth()->user()?->name ? auth()->id() : null]));
    }

    public function startAction(): Action
    {
        return Action::make('start')->label('対応開始')->color('warning')
            ->modalHeading('対応を開始する')->modalDescription('担当者を自分にして「対応中」にします。')->modalSubmitActionLabel('対応を開始')->modalCancelActionLabel('開始せず閉じる')->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
            ->schema([
                Textarea::make('reason')->label('作業メモ')->rows(3)->required(),
                \Filament\Forms\Components\Toggle::make('pause_alerts')->label('対応中は新規アラート（Slack・メール・電話）を一時停止する')->default(false)->live(),
                Select::make('pause_minutes')->label('停止時間')->options([30 => '30 分', 60 => '1 時間', 120 => '2 時間', 240 => '4 時間'])->default(60)->visible(fn ($get) => (bool) $get('pause_alerts')),
            ])
            ->action(function (array $data) {
                $this->perform('start', ['reason' => $data['reason']]);
                if (! empty($data['pause_alerts']) && $this->flashLevel === 'ok') {
                    try {
                        $p = app(\App\Monitoring\Services\NotificationSettings::class)->pauseAlerts((int) ($data['pause_minutes'] ?? 60), $this->actor(), '対応開始: '.$this->publicId.' '.mb_substr((string) $data['reason'], 0, 200));
                        $this->flash .= ' アラートを '.$p['until']->setTimezone('Asia/Tokyo')->format('H:i').' まで停止しました。';
                    } catch (\InvalidArgumentException $e) {
                        $this->flash .= ' （アラート停止は失敗: '.$e->getMessage().'）';
                    }
                }
            });
    }

    public function noteAction(): Action
    {
        return Action::make('note')->label('メモ')->color('gray')
            ->modalHeading('作業メモを追加')->modalSubmitActionLabel('メモを記録')->modalCancelActionLabel('記録せず閉じる')->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
            ->schema([Textarea::make('reason')->label('メモ')->rows(4)->required()])
            ->action(fn (array $data) => $this->perform('note', $data));
    }

    public function snoozeAction(): Action
    {
        $max = (int) config('monitoring.notification.max_suppression_hours', 24);

        return Action::make('snooze')->label('通知抑止')->color('gray')
            ->modalHeading('通知を一時的に止める')->modalDescription("Slack・メール・電話の再通知を止めます。ログ収集・状態記録・未解決件数は止まりません。最大 {$max} 時間。")->modalSubmitActionLabel('抑止する')->modalCancelActionLabel('抑止せず閉じる')->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
            ->schema([
                Select::make('hours')->label('期限')->options([1 => '1時間', 2 => '2時間', 4 => '4時間', 8 => '8時間', 24 => '24時間'])->default(2)->required(),
                Textarea::make('reason')->label('理由（必須）')->rows(3)->required(),
            ])
            ->action(fn (array $data) => $this->perform('snooze', $data));
    }

    public function unsnoozeAction(): Action
    {
        return Action::make('unsnooze')->label('抑止解除')->color('gray')->requiresConfirmation()
            ->modalHeading('通知抑止を解除する')->modalSubmitActionLabel('解除する')->modalCancelActionLabel('解除せず閉じる')->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
            ->action(fn () => $this->perform('unsnooze'));
    }

    public function markRecoveredAction(): Action
    {
        return Action::make('markRecovered')->label('復旧確認')->color('success')
            ->modalHeading('復旧を人手で確認した')->modalDescription('ログが静かになっただけでは復旧になりません。対象リクエスト等を再確認した内容を記録してください。チェック由来の案件は再確認予約で自動復旧もできます。')
            ->modalSubmitActionLabel('復旧確認を記録')->modalCancelActionLabel('記録せず閉じる')->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
            ->schema([Textarea::make('reason')->label('再確認した内容（必須）')->rows(4)->required()])
            ->action(fn (array $data) => $this->perform('mark_recovered', $data));
    }

    public function resolveIncidentAction(): Action
    {
        return Action::make('resolveIncident')->label('対処完了')->color('danger')
            ->modalHeading('対処完了として解決にする')->modalDescription('原因未確定でも「未確定」を選べます。推測を確定原因として入力しないでください。')
            ->modalSubmitActionLabel('解決にする')->modalCancelActionLabel('解決にせず閉じる')->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
            ->schema([
                Select::make('cause_category')->label('原因分類')->required()->options([
                    'data_delay' => '元データの遅延・未受信', 'batch_failure' => 'バッチ失敗・未実行', 'lock_contention' => '排他ロック競合', 'config' => '設定・スケジュール不備',
                    'external' => '外部システム・接続先', 'application_bug' => 'アプリ不具合', 'infra' => 'インフラ・容量', 'monitoring' => '監視側の問題（誤検知含む）', 'undetermined' => '未確定',
                ]),
                Textarea::make('action_taken')->label('実施内容')->rows(3)->required(),
                Textarea::make('verification')->label('検証結果（どう確認したか）')->rows(3)->required(),
            ])
            ->action(fn (array $data) => $this->perform('resolve', $data));
    }

    public function closeAction(): Action
    {
        return Action::make('close')->label('終了')->color('gray')->requiresConfirmation()
            ->modalHeading('案件を終了する')->modalDescription('最終確認として終了します。再発した場合は自動で再オープンされ、世代が増えます。')->modalSubmitActionLabel('終了する')->modalCancelActionLabel('終了せず閉じる')->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
            ->action(fn () => $this->perform('close'));
    }

    public function reopenAction(): Action
    {
        return Action::make('reopen')->label('再オープン')->color('gray')->requiresConfirmation()
            ->modalHeading('案件を再オープンする')->modalSubmitActionLabel('再オープンする')->modalCancelActionLabel('再オープンせず閉じる')->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
            ->schema([Textarea::make('reason')->label('理由')->rows(3)->required()])
            ->action(fn (array $data) => $this->perform('reopen', $data));
    }

    public function recheckAction(): Action
    {
        return Action::make('recheck')->label('再確認予約')->color('info')
            ->modalHeading('読取り専用の再確認を予約する')->modalDescription('チェックと対象を固定した実行要求だけを保存します。再集計・再送・ロック解除は行いません。同一対象は最短5分間隔で、SQL時間予算内で実行されます。')
            ->modalSubmitActionLabel('予約する')->modalCancelActionLabel('予約せず閉じる')->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
            ->visible(fn () => (bool) $this->incident()?->check_id)
            ->action(function () {
                $incident = $this->incident();
                try {
                    $this->access()->authorize(auth()->user(), MonitorAccess::RESPOND, $this->siteCode((int) $incident->site_id));
                    $check = CheckModel::query()->findOrFail($incident->check_id);
                    $scope = (array) $incident->scope_json;
                    if ($incident->business_date) {
                        $scope['business_date'] = $incident->business_date->format('Y-m-d');
                    }
                    $req = app(RecheckService::class)->enqueue($check, $scope, $this->access()->actor(auth()->user()), $incident);
                    $this->flash = '再確認を予約しました（'.$req->request_id.'）。結果はこのページの「再確認」欄に表示されます。';
                    $this->flashLevel = 'ok';
                } catch (InvalidTransition|AccessDenied $e) {
                    $this->flash = $e->getMessage();
                    $this->flashLevel = 'error';
                }
            });
    }
}
