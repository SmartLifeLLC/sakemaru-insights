<?php

namespace App\Filament\Monitor\Pages;

use App\Filament\Monitor\MonitorPage;
use App\Models\Monitoring\CallAttempt;
use App\Models\Monitoring\Contact;
use App\Models\Monitoring\Counter;
use App\Monitoring\Notify\MailNotifier;
use App\Monitoring\Services\ContactService;
use App\Monitoring\Services\CounterService;
use App\Monitoring\Services\MonitorAccess;
use App\Monitoring\Services\NotificationDispatcher;
use App\Monitoring\Services\NotificationSettings;
use App\Monitoring\Services\NotificationTestService;
use App\Monitoring\Services\SecretStore;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;

/**
 * 通知設定（v2 第7章 / V-116〜121, V-150〜166）。
 * 接続情報（SMTP・Twilio・Webhook）は monitor.delivery_admin だけが閲覧・編集でき、値は伏字（末尾 4 文字）で表示し、空欄保存は変更しない。
 */
class Notifications extends MonitorPage
{
    protected static ?string $title = '通知設定';

    protected static ?string $slug = 'notifications';

    protected static string $navKey = 'notifications';

    protected static string $description = '監視結果の通知先（Slack・メール・電話）と配送動作を管理します。';

    protected string $view = 'monitor.pages.notifications';

    public function isDeliveryAdmin(): bool
    {
        return $this->can(MonitorAccess::DELIVERY_ADMIN);
    }

    private function guard(): void
    {
        $this->access()->authorize(auth()->user(), MonitorAccess::DELIVERY_ADMIN);
    }

    private function run(callable $fn, ?string $ok = null): void
    {
        try {
            $this->guard();
            $r = $fn();
            app(NotificationSettings::class)->forget();
            app(SecretStore::class)->forget();
            if ($ok) {
                $this->ok($ok);
            } elseif (is_array($r) && isset($r['ok'])) {
                $r['ok'] ? $this->ok($r['message']) : $this->fail($r['message']);
            }
        } catch (\App\Monitoring\Exceptions\AccessDenied) {
            $this->fail('通知設定の変更・テストは配送管理者（monitor.delivery_admin）だけが行えます。');
        } catch (\InvalidArgumentException|\App\Monitoring\Exceptions\InvalidTransition $e) {
            $this->fail($e->getMessage());
        } catch (\Throwable $e) {
            report($e);
            $this->fail('処理できませんでした（内部エラー: '.class_basename($e).'）。管理者に連絡してください。');
        }
    }

    private function reasonField(): Textarea
    {
        return Textarea::make('reason')->label('変更理由（必須・監査に保存）')->rows(2)->required()->minLength(2);
    }

    /** @return array<string,mixed> */
    public function data(): array
    {
        $now = CarbonImmutable::now('UTC');
        $settings = app(NotificationSettings::class);
        $secrets = app(SecretStore::class);
        $sum = [];
        foreach (Counter::query()->where('counter_key', CounterService::KEY_NOTIFICATION_HOURLY)->where('bucket_start', '>=', $now->subHours(23)->startOfHour())->get(['counts_json']) as $r) {
            foreach ((array) $r->counts_json as $k => $v) {
                $sum[$k] = ($sum[$k] ?? 0) + (int) $v;
            }
        }
        $route = (string) config('monitoring.notification.default_route', 'error_channel');
        $admin = $this->isDeliveryAdmin();

        return [
            'admin' => $admin,
            'mail' => $settings->mail(),
            'phone' => $settings->phone(),
            'phone_allowed' => $settings->phoneAllowedHere(),
            'smtp_ok' => app(MailNotifier::class)->configured(),
            'twilio_ok' => $secrets->twilioConfigured(),
            'slack' => ['name' => config('monitoring.notification.routes.'.$route.'.display_name'), 'source' => $secrets->source('slack.webhook_url')],
            'global_disabled' => app(NotificationDispatcher::class)->isGloballyDisabled($now),
            'sum' => $sum,
            'contacts' => Contact::query()->orderBy('order_no')->orderBy('id')->get(),
            'tonight' => app(\App\Monitoring\Notify\PhoneNotifier::class)->windowStatus($now),
            'calls' => CallAttempt::query()->orderByDesc('id')->limit(20)->get(),
            'contactNames' => Contact::query()->pluck('name', 'id')->all(),
            // 接続情報は delivery_admin のときだけ組み立てる（伏字・出所のみ）
            'secrets' => $admin ? collect(SecretStore::KEYS)->map(fn ($m, $k) => ['label' => $m[0], 'env' => $m[1], 'secret' => $m[2], 'display' => $secrets->display($k), 'source' => $secrets->source($k)])->all() : [],
        ];
    }

    // ------------------------------------------------------------------ 通知の動作

    public function mailSettingsAction(): Action
    {
        return Action::make('mailSettings')->label('メール設定')->color('gray')->size('sm')
            ->modalHeading('メール通知の動作')->modalSubmitActionLabel('保存する')->modalCancelActionLabel('保存せず閉じる')->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
            ->visible(fn () => $this->isDeliveryAdmin())
            ->fillForm(fn () => ($m = app(NotificationSettings::class)->mail()) + ['recipients_text' => implode("\n", (array) $m['recipients'])])
            ->schema([
                Toggle::make('enabled')->label('メールで通知する（24 時間）'),
                Textarea::make('recipients_text')->label('宛先（1 行に 1 件）')->rows(3)->required(),
                Toggle::make('include_reminder')->label('継続（再通知）もメールする'),
                Toggle::make('include_recovery')->label('復旧もメールする'),
                Grid::make(2)->schema([
                    TextInput::make('min_interval_minutes')->label('最短間隔（分）')->numeric()->minValue(0)->maxValue(1440)->helperText('この間隔の中の案件は 1 通にまとめる'),
                    TextInput::make('max_per_hour')->label('1 時間の上限（通）')->numeric()->minValue(0)->maxValue(1000)->helperText('超えた分は翌時間にまとめて送る（捨てない）'),
                ]),
                $this->reasonField(),
            ])
            ->action(fn (array $data) => $this->run(fn () => app(NotificationSettings::class)->save('mail', ['enabled' => (bool) $data['enabled'], 'recipients' => preg_split('/[\r\n,]+/', (string) $data['recipients_text']), 'include_reminder' => (bool) $data['include_reminder'], 'include_recovery' => (bool) $data['include_recovery'],
                'min_interval_minutes' => (int) $data['min_interval_minutes'], 'max_per_hour' => (int) $data['max_per_hour']], $this->actor(), (string) $data['reason']), 'メール通知の設定を保存しました。'));
    }

    public function phoneSettingsAction(): Action
    {
        return Action::make('phoneSettings')->label('電話設定')->color('gray')->size('sm')
            ->modalHeading('電話通知の動作')->modalDescription('夜間帯の新規・再発のエラーだけ、担当者名簿の順に電話します。1 を押すと「確認」になり、後続の発信を止めます。')
            ->modalSubmitActionLabel('保存する')->modalCancelActionLabel('保存せず閉じる')->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
            ->visible(fn () => $this->isDeliveryAdmin())
            ->fillForm(fn () => app(NotificationSettings::class)->phone())
            ->schema([
                Toggle::make('enabled')->label('電話で通知する'),
                Grid::make(2)->schema([TextInput::make('window_from')->label('開始（JST）')->placeholder('22:00')->required(), TextInput::make('window_to')->label('終了（JST）')->placeholder('08:00')->required()]),
                CheckboxList::make('severities')->label('対象の重大度')->options(['ERROR' => 'エラー', 'CRITICAL' => '重大'])->required(),
                Section::make('多発防止')->description('電話は人を起こす通知です。猶予・間隔・上限で回数を抑えます（0 で無効）。上限を超えた分は Slack・メールだけになります。')->compact()->columns(2)->schema([
                    TextInput::make('delay_minutes')->label('発信前の猶予（分）')->numeric()->minValue(0)->maxValue(120)->helperText('その間に復旧・確認されたら電話しない'),
                    TextInput::make('cooldown_minutes')->label('発信列の間隔（分）')->numeric()->minValue(0)->maxValue(1440)->helperText('前の列が終わってから次の列まで。その間の案件は 1 本にまとめる'),
                    TextInput::make('per_incident_cooldown_hours')->label('同じ案件の再発（時間）')->numeric()->minValue(0)->maxValue(168)->helperText('一度電話した案件はこの時間、再発しても電話しない'),
                    TextInput::make('max_columns_per_window')->label('1 晩の発信列の上限')->numeric()->minValue(0)->maxValue(100),
                    TextInput::make('max_calls_per_contact_per_window')->label('担当者 1 人の 1 晩の着信上限')->numeric()->minValue(0)->maxValue(100),
                ]),
                $this->reasonField(),
            ])
            ->action(fn (array $data) => $this->run(fn () => app(NotificationSettings::class)->save('phone', ['enabled' => (bool) $data['enabled'], 'window_from' => $data['window_from'], 'window_to' => $data['window_to'], 'severities' => (array) $data['severities'],
                'delay_minutes' => (int) $data['delay_minutes'], 'cooldown_minutes' => (int) $data['cooldown_minutes'], 'per_incident_cooldown_hours' => (int) $data['per_incident_cooldown_hours'],
                'max_columns_per_window' => (int) $data['max_columns_per_window'], 'max_calls_per_contact_per_window' => (int) $data['max_calls_per_contact_per_window']], $this->actor(), (string) $data['reason']), '電話通知の設定を保存しました。'));
    }

    // ------------------------------------------------------------------ 接続情報

    public function secretsAction(): Action
    {
        $fields = [];
        foreach (SecretStore::KEYS as $k => [$label, $env, $secret]) {
            $fields[] = ($secret ? TextInput::make('v.'.str_replace('.', '__', $k))->password()->revealable(false) : TextInput::make('v.'.str_replace('.', '__', $k)))
                ->label($label)->placeholder(fn () => app(SecretStore::class)->display($k) ?? '未設定')
                ->helperText(fn () => match (app(SecretStore::class)->source($k)) {
                    'screen' => '画面の設定を使用中', 'env' => '環境変数 '.$env.' の値を使用中', default => '未設定'
                }.'（空欄のまま保存すると変更しません）');
        }

        return Action::make('secrets')->label('接続情報を編集')->color('gray')->icon('heroicon-o-key')
            ->modalHeading('接続情報（SMTP・Twilio・Slack）')->modalDescription('値は暗号化して保存し、画面・ログ・監査には表示しません。画面の設定は環境変数より優先されます。')
            ->modalSubmitActionLabel('保存する')->modalCancelActionLabel('保存せず閉じる')->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])->modalWidth('2xl')
            ->visible(fn () => $this->isDeliveryAdmin())
            ->schema(array_merge([Grid::make(2)->schema($fields)], [
                CheckboxList::make('clear')->label('画面の設定を削除して環境変数へ戻す')->options(collect(SecretStore::KEYS)->map(fn ($m) => $m[0])->all())->columns(2),
                $this->reasonField(),
            ]))
            ->action(function (array $data) {
                $values = [];
                foreach ((array) ($data['v'] ?? []) as $k => $v) {
                    $values[str_replace('__', '.', $k)] = $v;
                }
                $this->run(function () use ($values, $data) {
                    $changed = app(SecretStore::class)->save($values, $this->actor(), (string) $data['reason'], (array) ($data['clear'] ?? []));
                    $this->ok($changed ? '保存しました（変更: '.count($changed).' 項目）。' : '変更はありませんでした。');
                });
            });
    }

    public function runTest(string $kind, ?int $contactId = null): void
    {
        $this->run(fn () => app(NotificationTestService::class)->run($kind, $this->actor(), $contactId));
    }

    // ------------------------------------------------------------------ 担当者名簿

    public function contactAction(): Action
    {
        return Action::make('contact')->label(fn (array $arguments) => isset($arguments['id']) ? '編集' : '担当者を追加')->color(fn (array $arguments) => isset($arguments['id']) ? 'gray' : 'primary')->size('sm')
            ->modalHeading(fn (array $arguments) => isset($arguments['id']) ? '担当者を編集' : '担当者を追加')
            ->modalSubmitActionLabel('保存する')->modalCancelActionLabel('保存せず閉じる')->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
            ->visible(fn () => $this->isDeliveryAdmin())
            ->fillForm(fn (array $arguments) => ($c = isset($arguments['id']) ? Contact::query()->find($arguments['id']) : null) ? ['name' => $c->name, 'phone' => '', 'enabled' => (bool) $c->enabled, 'active_from' => $c->active_from, 'active_to' => $c->active_to] : ['enabled' => true])
            ->schema(fn (array $arguments) => [
                TextInput::make('name')->label('氏名')->required()->maxLength(100),
                TextInput::make('phone')->label('電話番号')->placeholder(isset($arguments['id']) ? (Contact::query()->find($arguments['id'])?->maskedPhone().'（空欄は変更しない）') : '090-1234-5678')->required(! isset($arguments['id'])),
                Toggle::make('enabled')->label('発信対象にする'),
                Grid::make(2)->schema([TextInput::make('active_from')->label('在席 開始（任意）')->placeholder('22:00'), TextInput::make('active_to')->label('在席 終了（任意）')->placeholder('08:00')]),
                $this->reasonField(),
            ])
            ->action(function (array $arguments, array $data) {
                $c = isset($arguments['id']) ? Contact::query()->findOrFail($arguments['id']) : null;
                $this->run(fn () => app(ContactService::class)->save($c, $data, $this->actor(), (string) $data['reason']), '担当者名簿を保存しました。');
            });
    }

    public function moveContact(int $id, string $dir): void
    {
        $ids = Contact::query()->orderBy('order_no')->orderBy('id')->pluck('id')->all();
        $i = array_search($id, $ids, true);
        $j = $dir === 'up' ? $i - 1 : $i + 1;
        if ($i === false || $j < 0 || $j >= count($ids)) {
            return;
        }
        [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
        $this->run(fn () => app(ContactService::class)->reorder($ids, $this->actor(), '発信順の変更'), '発信順を変更しました。');
    }
}
