<?php

namespace App\Monitoring\Services;

use App\Models\Monitoring\Check as CheckModel;
use App\Monitoring\Checks;
use App\Monitoring\Contracts\Check;
use App\Monitoring\Contracts\CheckDefinition;

/**
 * コード登録された監視項目（仕様書 4.1 CheckRegistry / 第8章）。
 * 画面では許可した値のみ設定可能。判定ロジックは DB に持たない。
 */
class CheckRegistry
{
    /** @var array<class-string<Check>> */
    public const CHECKS = [
        // Insights 自己監視
        Checks\Monitor\CollectorStaleCheck::class,
        Checks\Monitor\CheckStaleCheck::class,
        Checks\Monitor\NotificationBacklogCheck::class,
        Checks\Monitor\StorageCheck::class,
        Checks\Monitor\HttpProbeCheck::class,
        Checks\Monitor\ProbeStaleCheck::class,
        Checks\Monitor\MetricsStaleCheck::class,
        Checks\Monitor\NodeResourcesCheck::class,
        // Trade
        Checks\Trade\CouponDailyCheck::class,
        Checks\Trade\MemberDailyCheck::class,
        Checks\Trade\HourlyReceiveCheck::class,
        Checks\Trade\TransferPendingCheck::class,
        Checks\Trade\DailyTransferCheck::class,
        Checks\Trade\ReportMailCheck::class,
        Checks\Trade\IntageSendCheck::class,
        Checks\Trade\FixedPointCheck::class,
        Checks\Trade\PromoReceiptCheck::class,
        Checks\Trade\DailyReportCheck::class,
        Checks\Trade\ItemLastUseCheck::class,
        Checks\Trade\QueueFailed::class,
        // 蔵
        Checks\Wms\AutoOrderRunCheck::class,
        Checks\Wms\AutoOrderCalcCheck::class,
        Checks\Wms\JxOrderGenerationCheck::class,
        Checks\Wms\JxTransmissionCheck::class,
        Checks\Wms\OrderTransmissionCheck::class,
        Checks\Wms\EosIncomingReceiveCheck::class,
        Checks\Wms\IncomingImportErrorsCheck::class,
        Checks\Wms\QueueFailed::class,
        // 勘定衆
        Checks\Core\SchedulerHeartbeatCheck::class,
        Checks\Core\StatsJobSlotCheck::class,
        Checks\Core\StatsJobDatesCheck::class,
        Checks\Core\StatsDailyFinalizeCheck::class,
        Checks\Core\SalesPerformanceCheck::class,
        Checks\Core\ClosingProcessCheck::class,
        Checks\Core\ExternalCollaborationCheck::class,
        Checks\Core\QueueFailed::class,
        // 千里眼
        Checks\Search\SyncItemsFullCheck::class,
        Checks\Search\SyncItemStocksCheck::class,
        Checks\Search\QueueFailed::class,
        // 飛脚
        Checks\Delivery\OperationSyncCheck::class,
        Checks\Delivery\CollectedMoneyCheck::class,
        Checks\Delivery\ExternalSyncCheck::class,
        Checks\Delivery\ContainerCollectCheck::class,
        Checks\Delivery\QueueFailed::class,
    ];

    /** @var array<string,Check>|null keyed by "APP:check_key" */
    private ?array $instances = null;

    /** @return array<string,Check> */
    public function all(): array
    {
        if ($this->instances === null) {
            $this->instances = [];
            foreach (self::CHECKS as $class) {
                /** @var Check $c */
                $c = app($class);
                $d = $c->definition();
                $this->instances[$d->applicationCode.':'.$d->key] = $c;
            }
        }

        return $this->instances;
    }

    public function find(string $applicationCode, string $checkKey): ?Check
    {
        return $this->all()[strtoupper($applicationCode).':'.$checkKey] ?? null;
    }

    public function definition(string $applicationCode, string $checkKey): ?CheckDefinition
    {
        return $this->find($applicationCode, $checkKey)?->definition();
    }

    public function definitionByKey(string $checkKey): ?CheckDefinition
    {
        foreach ($this->all() as $check) {
            if ($check->definition()->key === $checkKey) {
                return $check->definition();
            }
        }

        return null;
    }

    /** コード定義 → DB 行の同期（有効化・猶予・config の運用値は保持）。 */
    public function sync(SiteRegistry $sites): void
    {
        $this->assertFeatureMapping();
        foreach ($this->all() as $check) {
            $d = $check->definition();
            $site = $sites->site($d->applicationCode);
            if (! $site) {
                continue;
            }
            $row = CheckModel::query()->where('site_id', $site->id)->where('check_key', $d->key)->first();
            if (! $row) {
                CheckModel::query()->create([
                    'site_id' => $site->id,
                    'check_key' => $d->key,
                    'display_name' => $d->displayName,
                    'enabled' => $d->implemented,
                    'implemented' => $d->implemented,
                    'interval_seconds' => $d->intervalSeconds,
                    'grace_seconds' => $d->graceSeconds,
                    'next_due_at' => now('UTC'),
                    'config_json' => $d->defaultConfig,
                    'version' => $d->version,
                    'runbook_key' => $d->runbookKey,
                ]);
            } else {
                $row->display_name = $d->displayName;
                $row->implemented = $d->implemented;
                $row->version = $d->version;
                $row->runbook_key = $d->runbookKey;
                $row->interval_seconds = (int) ($row->rule_json['interval_seconds'] ?? $d->intervalSeconds); // 監視ルールで変えた間隔を保持
                if (! $d->implemented) {
                    $row->enabled = false;
                }
                $row->save();
            }
        }
        // 定義から消えたチェック（置き換え済み）は停止して「廃止」と表示する。行と履歴は残す
        $known = array_map(fn ($c) => $c->definition()->key, $this->all());
        foreach (CheckModel::query()->whereNotIn('check_key', $known)->where('implemented', true)->get() as $row) {
            $row->forceFill(['enabled' => false, 'implemented' => false, 'display_name' => preg_replace('/（廃止）$/u', '', $row->display_name).'（廃止）'])->save();
        }
        $features = app(FeatureRegistry::class);
        $features->sync();
        $features->recomputeCoverage();
    }

    /**
     * すべての登録チェックが機能に属することを検証する（仕様書 v2 V-013）。
     *
     * @throws \LogicException
     */
    public function assertFeatureMapping(): void
    {
        $features = app(FeatureRegistry::class);
        foreach ($this->all() as $check) {
            $d = $check->definition();
            if ($features->featureForCheck($d->key) === null) {
                throw new \LogicException("監視項目 {$d->key} がどの機能（FeatureRegistry::FEATURES）にも属していません。");
            }
        }
    }

    /** @return array<string,mixed> 有効な設定（default + DB の許可キー） */
    public function effectiveConfig(CheckDefinition $d, ?array $stored): array
    {
        $cfg = $d->defaultConfig;
        foreach ((array) $stored as $k => $v) {
            if (in_array($k, $d->editableConfigKeys, true)) {
                $cfg[$k] = $v;
            }
        }

        return $cfg;
    }
}
