<?php

namespace App\Monitoring\Services;

use App\Models\Monitoring\Check as CheckModel;
use App\Models\Monitoring\Feature;
use App\Models\Monitoring\Site;
use App\Models\Monitoring\Target;
use Carbon\CarbonImmutable;

/**
 * 機能（サービス内で顧客が使う/使わない単位）の定義と ON/OFF（仕様書 v2 第4章）。
 *
 * - サービス → 機能 → 監視項目 の 3 段。上位が無効なら下位は実行しない（「未監視」表示）。
 * - 推奨値は業務設定から自動検出して表示するが、自動では切り替えない（管理者が確定、監査に残す）。
 * - すべての check_key はいずれかの機能に属する（属さないチェックは同期時に例外）。
 */
class FeatureRegistry
{
    /**
     * feature_key => [site, name, checks[], default(初回作成時の enabled), detect: [sql, days?, reason], sort]
     * detect.sql は業務DBへの軽量 SELECT（1 行返れば「使用あり」）。:since は days 日前（JST 日付）に置換して bind する。
     * detect.kind = 'table' はテーブル存在のみ確認（LIMIT 0）。detect.kind = 'target:KIND' は監視側の登録有無。
     */
    public const FEATURES = [
        // ---- 勘定衆 CORE ----
        'core.stats' => ['site' => 'CORE', 'name' => '統計集計', 'sort' => 10, 'default' => false,
            'checks' => ['core.stats_daily_aggregate', 'core.stats_daily_finalize', 'core.stats_sales_performance'],
            'detect' => ['sql' => 'SELECT 1 FROM stats_import_runs WHERE created_at >= ? LIMIT 1', 'days' => 7, 'reason' => '直近 7 日に統計集計（stats_import_runs）の実行あり']],
        'core.closing' => ['site' => 'CORE', 'name' => '締め処理', 'sort' => 20, 'default' => false,
            'checks' => ['core.closing_process'],
            'detect' => ['sql' => 'SELECT 1 FROM closing_processes WHERE created_at >= ? LIMIT 1', 'days' => 90, 'reason' => '直近 90 日に締め処理（closing_processes）の実行あり']],
        'core.external' => ['site' => 'CORE', 'name' => '外部連携取込', 'sort' => 30, 'default' => false,
            'checks' => ['core.external_collaboration'],
            'detect' => ['sql' => 'SELECT 1 FROM external_collaboration_data WHERE created_at >= ? LIMIT 1', 'days' => 30, 'reason' => '直近 30 日に外部連携（external_collaboration_data）の取込あり']],
        'core.queue' => ['site' => 'CORE', 'name' => 'キュー失敗ジョブ', 'sort' => 80, 'default' => false,
            'checks' => ['core.queue_failed'],
            'detect' => ['kind' => 'table', 'table' => 'failed_jobs', 'reason' => 'failed_jobs テーブルあり']],

        // ---- 乃蓮 TRADE（v1 から稼働中のため既定 ON）----
        'trade.pos_stats' => ['site' => 'TRADE', 'name' => 'POS 受信・統計', 'sort' => 10, 'default' => true,
            'checks' => ['trade.coupon_daily', 'trade.member_daily', 'trade.hourly_receive', 'trade.daily_transfer', 'trade.transfer_pending', 'trade.item_last_use'],
            'detect' => ['sql' => 'SELECT 1 FROM ret_tm_receive_requests WHERE created_at >= ? LIMIT 1', 'days' => 7, 'reason' => '直近 7 日に POS 受信（ret_tm_receive_requests）あり']],
        'trade.external_send' => ['site' => 'TRADE', 'name' => '帳票・外部送信', 'sort' => 20, 'default' => true,
            'checks' => ['trade.mail', 'trade.intage', 'trade.fixed_point', 'trade.promo', 'trade.daily_report'],
            'detect' => ['sql' => 'SELECT 1 FROM ret_report_mail_schedules WHERE schedule_enabled = 1 UNION ALL SELECT 1 FROM ret_intage_pos_send_settings WHERE schedule_enabled = 1 UNION ALL SELECT 1 FROM ret_daily_sales_report_schedules WHERE schedule_enabled = 1 LIMIT 1', 'reason' => '有効な送信スケジュールあり']],
        'trade.queue' => ['site' => 'TRADE', 'name' => 'キュー失敗ジョブ', 'sort' => 80, 'default' => false,
            'checks' => ['trade.queue_failed'],
            'detect' => ['kind' => 'table', 'table' => 'ret_failed_jobs', 'reason' => 'ret_failed_jobs テーブルあり']],

        // ---- 蔵 WMS ----
        'wms.auto_order' => ['site' => 'WMS', 'name' => '自動発注', 'sort' => 10, 'default' => false,
            'checks' => ['wms.auto_order_run', 'wms.auto_order_calc'],
            'detect' => ['sql' => 'SELECT 1 FROM wms_warehouse_auto_order_settings WHERE is_auto_order_enabled = 1 LIMIT 1', 'reason' => '自動発注が有効な倉庫あり']],
        'wms.jx' => ['site' => 'WMS', 'name' => 'JX 送受信・発注送信', 'sort' => 20, 'default' => false,
            'checks' => ['wms.jx_order_generation', 'wms.jx_transmission', 'wms.order_transmission'],
            'detect' => ['sql' => 'SELECT 1 FROM wms_jx_transmission_logs WHERE created_at >= ? LIMIT 1', 'days' => 30, 'reason' => '直近 30 日に JX 送受信あり']],
        'wms.eos_incoming' => ['site' => 'WMS', 'name' => 'EOS 入荷予定受信', 'sort' => 30, 'default' => false,
            'checks' => ['wms.eos_incoming_receive', 'wms.incoming_import_errors'],
            'detect' => ['sql' => 'SELECT 1 FROM wms_eos_incoming_receive_schedules WHERE is_enabled = 1 LIMIT 1', 'reason' => '有効な EOS 受信スケジュールあり']],
        'wms.queue' => ['site' => 'WMS', 'name' => 'キュー失敗ジョブ', 'sort' => 80, 'default' => false,
            'checks' => ['wms.queue_failed'],
            'detect' => ['kind' => 'table', 'table' => 'wms_failed_jobs', 'reason' => 'wms_failed_jobs テーブルあり']],

        // ---- 飛脚 DELIVERY ----
        'delivery.sync' => ['site' => 'DELIVERY', 'name' => '配送端末の同期', 'sort' => 10, 'default' => false,
            'checks' => ['delivery.operation_sync'],
            'detect' => ['sql' => 'SELECT 1 FROM dlv_operations WHERE created_at >= ? LIMIT 1', 'days' => 30, 'reason' => '直近 30 日に配送運行（dlv_operations）あり']],
        'delivery.money' => ['site' => 'DELIVERY', 'name' => '集金チェック', 'sort' => 20, 'default' => false,
            'checks' => ['delivery.collected_money_check'],
            'detect' => ['sql' => 'SELECT 1 FROM dlv_item_delivery_collected_monies WHERE created_at >= ? LIMIT 1', 'days' => 30, 'reason' => '直近 30 日に集金データあり']],
        'delivery.external' => ['site' => 'DELIVERY', 'name' => '勘定衆への送信（集金・容器回収）', 'sort' => 30, 'default' => false,
            'checks' => ['delivery.external_sync', 'delivery.container_collect'],
            'detect' => ['sql' => "SELECT 1 FROM external_collaboration_data WHERE external_collaboration_partner = 'MOBILE_COLLECT' AND created_at >= ? LIMIT 1", 'days' => 30, 'reason' => '直近 30 日に MOBILE_COLLECT からの連携あり']],
        'delivery.queue' => ['site' => 'DELIVERY', 'name' => 'キュー失敗ジョブ', 'sort' => 80, 'default' => false,
            'checks' => ['delivery.queue_failed'],
            'detect' => ['kind' => 'table', 'table' => 'dlv_failed_jobs', 'reason' => 'dlv_failed_jobs テーブルあり']],

        // ---- 千里眼 SEARCH ----
        'search.sync' => ['site' => 'SEARCH', 'name' => '検索データの定期更新', 'sort' => 10, 'default' => false,
            'checks' => ['search.sync_items_full', 'search.sync_item_stocks'],
            'detect' => ['sql' => 'SELECT 1 FROM srh_search_sync_states LIMIT 1', 'reason' => '検索同期状態（srh_search_sync_states）あり']],
        'search.queue' => ['site' => 'SEARCH', 'name' => 'キュー失敗ジョブ', 'sort' => 80, 'default' => false,
            'checks' => ['search.queue_failed'],
            'detect' => ['kind' => 'table', 'table' => 'srh_failed_jobs', 'reason' => 'srh_failed_jobs テーブルあり']],

        // ---- 算盤 INSIGHTS（自己監視は常時 ON）----
        'insights.self' => ['site' => 'INSIGHTS', 'name' => '監視基盤の自己監視', 'sort' => 10, 'default' => true,
            'checks' => ['monitor.collector_stale', 'monitor.check_stale', 'monitor.notification', 'monitor.storage', 'monitor.probe_stale', 'monitor.metrics_stale'],
            'detect' => null],
        'insights.probe' => ['site' => 'INSIGHTS', 'name' => 'HTTP 外形監視（各サービスの /up）', 'sort' => 15, 'default' => true,
            'checks' => ['http.probe'],
            'detect' => ['kind' => 'targets_any:HTTP_PROBE', 'reason' => 'HTTP プローブ対象の登録あり']],
        'insights.nodes' => ['site' => 'INSIGHTS', 'name' => 'サーバー資源', 'sort' => 20, 'default' => true,
            'checks' => ['node.resources'],
            'detect' => ['kind' => 'nodes', 'reason' => '資源収集ノードの登録あり']],
    ];

    /** サービス共通の機能（アプリログ・HTTP 死活）。全サービスに作る */
    public const PER_SITE_FEATURES = [
        'app_log' => ['name' => 'アプリログ（ERROR 以上）', 'sort' => 90, 'kind' => 'APPLICATION_LOG'],
        'http' => ['name' => 'HTTP 死活・応答時間', 'sort' => 91, 'kind' => 'HTTP_PROBE'],
    ];

    /** @var array<string,Feature>|null keyed "SITE_ID:feature_key" */
    private ?array $cache = null;

    public function __construct(private readonly SiteRegistry $sites, private readonly AuditService $audit) {}

    /** @return array<string,array<string,mixed>> 全機能定義（サービス共通機能を展開済み） */
    public function definitions(): array
    {
        $defs = self::FEATURES;
        foreach (array_keys((array) config('monitoring.sites', [])) as $code) {
            if ($code === 'INSIGHTS') {
                continue;
            }
            foreach (self::PER_SITE_FEATURES as $suffix => $d) {
                $defs[strtolower($code).'.'.$suffix] = ['site' => $code, 'name' => $d['name'], 'sort' => $d['sort'], 'default' => false, 'checks' => [],
                    'detect' => ['kind' => 'target:'.$d['kind'], 'reason' => '監視対象（'.$d['name'].'）の登録あり'], 'target_kind' => $d['kind']];
            }
        }

        return $defs;
    }

    public function featureForCheck(string $checkKey): ?string
    {
        foreach (self::FEATURES as $key => $d) {
            if (in_array($checkKey, $d['checks'], true)) {
                return $key;
            }
        }

        return null;
    }

    /** 監視対象種別（APPLICATION_LOG / HTTP_PROBE）に対応するサービス共通機能キー */
    public function featureForTarget(string $siteCode, string $kind): ?string
    {
        foreach (self::PER_SITE_FEATURES as $suffix => $d) {
            if ($d['kind'] === $kind) {
                return strtolower($siteCode).'.'.$suffix;
            }
        }

        return null;
    }

    /** 定義 → DB 同期（冪等）。enabled は初回作成時だけ既定値を入れ、以後は運用値を保持する。 */
    public function sync(): void
    {
        $now = CarbonImmutable::now('UTC');
        foreach ($this->definitions() as $key => $d) {
            $site = $this->sites->site($d['site']);
            if (! $site) {
                continue;
            }
            $row = Feature::query()->where('site_id', $site->id)->where('feature_key', $key)->first();
            if (! $row) {
                Feature::query()->create([
                    'site_id' => $site->id, 'feature_key' => $key, 'display_name' => $d['name'], 'enabled' => (bool) $d['default'],
                    'sort_order' => $d['sort'], 'reason' => '初期登録（既定値）', 'updated_name' => 'system',
                ]);
            } elseif ($row->display_name !== $d['name'] || (int) $row->sort_order !== (int) $d['sort']) {
                $row->fill(['display_name' => $d['name'], 'sort_order' => $d['sort']])->save();
            }
        }
        // 監視項目に機能キーを付与
        foreach (CheckModel::query()->get() as $check) {
            $fk = $this->featureForCheck($check->check_key);
            if ($fk && $check->feature_key !== $fk) {
                $check->feature_key = $fk;
                $check->save();
            }
        }
        $this->cache = null;
    }

    /**
     * サービスの監視範囲を機能・対象から再計算する（画面の「監視中／未監視」表示）。
     * business_checks: 有効な業務機能に実装済み・有効な監視項目がある。application_log: 有効なログ収集対象がある。
     */
    public function recomputeCoverage(): void
    {
        $this->cache = null;
        $checks = CheckModel::query()->get();
        foreach ($this->sites->all() as $site) {
            $business = false;
            foreach ($checks->where('site_id', $site->id) as $c) {
                if ($site->application_code !== 'INSIGHTS' && $this->gate($c)['active']) {
                    $business = true;
                    break;
                }
            }
            $appLog = Target::query()->where('kind', 'APPLICATION_LOG')->where('site_id', $site->id)->where('enabled', true)->exists()
                || collect((array) config('monitoring.collectors', []))->contains(fn ($c) => strtoupper($c['application_code'] ?? '') === $site->application_code);
            $fk = $this->featureForTarget($site->application_code, 'APPLICATION_LOG');
            $f = $fk ? $this->feature((int) $site->id, $fk) : null;
            if ($f && ! $f->enabled) {
                $appLog = false;
            }
            $http = Target::query()->where('kind', 'HTTP_PROBE')->where('site_id', $site->id)->where('enabled', true)->exists();
            $cov = (array) ($site->coverage_json ?? []);
            $cov['business_checks'] = $business;
            $cov['application_log'] = $appLog;
            $cov['http_probe'] = $http;
            $site->coverage_json = $cov;
            $site->save();
            $this->sites->markCoverage($site, 'noop', false);
        }
    }

    /** @return array<string,Feature> */
    public function map(): array
    {
        if ($this->cache === null) {
            $this->cache = [];
            foreach (Feature::query()->get() as $f) {
                $this->cache[$f->site_id.':'.$f->feature_key] = $f;
            }
        }

        return $this->cache;
    }

    public function forget(): void
    {
        $this->cache = null;
    }

    public function feature(int $siteId, string $featureKey): ?Feature
    {
        return $this->map()[$siteId.':'.$featureKey] ?? null;
    }

    /**
     * 3 段ゲート: サービス有効 ∧ 機能有効 ∧ 監視項目有効（∧ 実装済み）。
     *
     * @return array{active:bool, reason:?string} reason: site_disabled / feature_disabled / check_disabled / unimplemented
     */
    public function gate(CheckModel $check): array
    {
        $site = $this->sites->siteById((int) $check->site_id);
        if (! $site || ! $site->enabled) {
            return ['active' => false, 'reason' => 'site_disabled'];
        }
        $fk = $check->feature_key ?: $this->featureForCheck($check->check_key);
        $feature = $fk ? $this->feature((int) $check->site_id, $fk) : null;
        if ($fk && $feature && ! $feature->enabled) {
            return ['active' => false, 'reason' => 'feature_disabled'];
        }
        if (! $check->implemented) {
            return ['active' => false, 'reason' => 'unimplemented'];
        }
        if (! $check->enabled) {
            return ['active' => false, 'reason' => 'check_disabled'];
        }

        return ['active' => true, 'reason' => null];
    }

    /** 監視対象（ログ・プローブ）のゲート */
    public function targetActive(Target $target): bool
    {
        if (! $target->enabled) {
            return false;
        }
        if ($target->site_id) {
            $site = $this->sites->siteById((int) $target->site_id);
            if (! $site || ! $site->enabled) {
                return false;
            }
            $fk = $this->featureForTarget($site->application_code, $target->kind);
            $f = $fk ? $this->feature((int) $site->id, $fk) : null;
            if ($f && ! $f->enabled) {
                return false;
            }
        }

        return true;
    }

    public static function gateLabel(?string $reason): string
    {
        return match ($reason) {
            'site_disabled' => '未監視（サービス停止）',
            'feature_disabled' => '未監視（機能未使用）',
            'check_disabled' => '未監視（項目停止）',
            'unimplemented' => '未実装',
            default => '監視中',
        };
    }

    /**
     * 推奨値の自動検出（1 日 1 回、owner ノード）。設定値は変えない。
     *
     * @return array<string,array{recommended:?bool,reason:string}>
     */
    public function detect(SourceReader $source, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $out = [];
        foreach ($this->definitions() as $key => $d) {
            $site = $this->sites->site($d['site']);
            $row = $site ? $this->feature((int) $site->id, $key) : null;
            if (! $row || empty($d['detect'])) {
                continue;
            }
            [$rec, $reason] = $this->detectOne($d['detect'], (int) $site->id, $source, $now);
            $row->recommended_enabled = $rec;
            $row->recommended_reason = mb_substr($reason, 0, 255);
            $row->recommended_checked_at = $now;
            $row->save();
            $out[$key] = ['recommended' => $rec, 'reason' => $reason];
        }
        $this->cache = null;

        return $out;
    }

    /** @return array{0:?bool,1:string} */
    private function detectOne(array $detect, int $siteId, SourceReader $source, CarbonImmutable $now): array
    {
        $kind = $detect['kind'] ?? 'sql';
        if (str_starts_with($kind, 'targets_any:')) {
            $has = Target::query()->where('kind', substr($kind, 12))->exists();

            return [$has, $has ? $detect['reason'] : '監視対象が未登録'];
        }
        if (str_starts_with($kind, 'target:')) {
            $targetKind = substr($kind, 7);
            $has = Target::query()->where('kind', $targetKind)->where('site_id', $siteId)->exists();
            // アプリログは、各サーバーの .env で宣言された収集器（台帳への同期前でも）があれば「登録あり」
            if (! $has && $targetKind === 'APPLICATION_LOG') {
                $has = \App\Models\Monitoring\Collector::query()->where('site_id', $siteId)->where('node_code', 'not like', '%@%')->exists();
            }

            return [$has, $has ? $detect['reason'] : '監視対象が未登録'];
        }
        if ($kind === 'nodes') {
            $has = \App\Models\Monitoring\Node::query()->exists();

            return [$has, $has ? $detect['reason'] : '資源収集ノードが未登録'];
        }
        if (! $source->isConfigured()) {
            return [null, '業務DB未接続のため判定できません'];
        }
        try {
            if ($kind === 'table') {
                $table = preg_replace('/[^A-Za-z0-9_]/', '', (string) $detect['table']);
                $source->select("SELECT 1 FROM {$table} LIMIT 1");

                return [true, $detect['reason']];
            }
            $bindings = [];
            if (! empty($detect['days'])) {
                $bindings[] = $now->setTimezone('Asia/Tokyo')->subDays((int) $detect['days'])->format('Y-m-d H:i:s');
            }
            $rows = $source->select($detect['sql'], $bindings);

            return [(bool) $rows, $rows ? $detect['reason'] : '該当データなし（'.($detect['days'] ?? '-').' 日以内）'];
        } catch (\App\Monitoring\Exceptions\SourceBudgetExceeded $e) {
            return [null, 'SQL 予算超過のため未判定'];
        } catch (\Throwable $e) {
            // テーブルが無い等。顧客がその機能を使っていないとみなす
            return [false, 'テーブルなし・参照不可（'.class_basename($e).'）'];
        }
    }

    /**
     * 機能の ON/OFF 変更（理由必須・監査）。
     *
     * @param  array{id:int|null,name:string}  $actor
     */
    public function setEnabled(Feature $feature, bool $enabled, array $actor, string $reason): Feature
    {
        if (trim($reason) === '') {
            throw new \App\Monitoring\Exceptions\InvalidTransition('変更理由は必須です。');
        }
        $before = ['enabled' => $feature->enabled];
        $feature->fill(['enabled' => $enabled, 'updated_user_id' => $actor['id'], 'updated_name' => $actor['name'], 'reason' => mb_substr($reason, 0, 1000)])->save();
        $site = $this->sites->siteById((int) $feature->site_id);
        $this->audit->record($actor, 'feature.'.($enabled ? 'enable' : 'disable'), 'feature', ($site?->application_code ?? '?').':'.$feature->feature_key, $before, ['enabled' => $enabled], $reason);
        $this->cache = null;

        return $feature;
    }

    /** @param array{id:int|null,name:string} $actor */
    public function setSiteEnabled(Site $site, bool $enabled, array $actor, string $reason): Site
    {
        if (trim($reason) === '') {
            throw new \App\Monitoring\Exceptions\InvalidTransition('変更理由は必須です。');
        }
        $before = ['enabled' => $site->enabled];
        $site->fill(['enabled' => $enabled, 'enabled_reason' => mb_substr($reason, 0, 1000), 'updated_user_id' => $actor['id'], 'updated_name' => $actor['name']])->save();
        $this->audit->record($actor, 'site.'.($enabled ? 'enable' : 'disable'), 'site', $site->application_code, $before, ['enabled' => $enabled], $reason);
        $this->sites->forget();
        $this->sites->markCoverage($site->fresh(), 'noop', false);

        return $site;
    }

    /** @param array{id:int|null,name:string} $actor */
    public function setCheckEnabled(CheckModel $check, bool $enabled, array $actor, string $reason): CheckModel
    {
        if (trim($reason) === '') {
            throw new \App\Monitoring\Exceptions\InvalidTransition('変更理由は必須です。');
        }
        if ($enabled && ! $check->implemented) {
            throw new \App\Monitoring\Exceptions\InvalidTransition('未実装の監視項目は有効化できません。');
        }
        $before = ['enabled' => $check->enabled];
        $check->fill(['enabled' => $enabled, 'updated_user_id' => $actor['id'], 'updated_name' => $actor['name'], 'reason' => mb_substr($reason, 0, 1000), 'paused_until' => null, 'consecutive_failures' => 0])->save();
        $site = $this->sites->siteById((int) $check->site_id);
        $this->audit->record($actor, 'check.'.($enabled ? 'enable' : 'disable'), 'check', ($site?->application_code ?? '?').':'.$check->check_key, $before, ['enabled' => $enabled], $reason);

        return $check;
    }

    /**
     * 検出済みの推奨値を設定へ反映する（推奨 ON → ON、推奨 OFF → OFF。未判定は触らない）。顧客の初期設定・画面の「推奨をすべて適用」用。
     *
     * @param  array{id:int|null,name:string}  $actor
     * @return array{enabled:string[],disabled:string[]} 変更した feature_key
     */
    public function applyRecommended(array $actor, string $reason): array
    {
        $changed = ['enabled' => [], 'disabled' => []];
        foreach (Feature::query()->whereNotNull('recommended_enabled')->orderBy('id')->get() as $f) {
            if ((bool) $f->enabled === (bool) $f->recommended_enabled) {
                continue;
            }
            $this->setEnabled($f, (bool) $f->recommended_enabled, $actor, $reason.'（推奨: '.$f->recommended_reason.'）');
            $changed[$f->recommended_enabled ? 'enabled' : 'disabled'][] = $f->feature_key;
        }
        $this->recomputeCoverage();

        return $changed;
    }
}
