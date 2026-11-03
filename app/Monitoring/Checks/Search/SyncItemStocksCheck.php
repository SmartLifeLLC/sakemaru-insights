<?php

namespace App\Monitoring\Checks\Search;

use App\Monitoring\Checks\BusinessCheck;
use App\Monitoring\Contracts\CheckDefinition;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\CheckResult;
use App\Monitoring\Services\Observation;

/**
 * search.sync_item_stocks: 在庫差分更新（15 分おき）。最終成功から 45 分超 → WARNING、90 分超・エラー状態 → ERROR。
 */
class SyncItemStocksCheck extends BusinessCheck
{
    public function definition(): CheckDefinition
    {
        return new CheckDefinition('search.sync_item_stocks', 'SEARCH', '在庫差分更新', '15 分おきの在庫差分更新が止まっていないか', 1, 300, 300, 'RB-16', false, false, 2, true,
            ['state_name' => 'searchable_item_stocks', 'warning_minutes' => 45, 'error_minutes' => 90], ['warning_minutes', 'error_minutes']);
    }

    public function evaluate(CheckContext $ctx): CheckResult
    {
        $name = (string) $ctx->config('state_name', 'searchable_item_stocks');
        $rows = $ctx->source()->select('SELECT status, last_success_at, last_updated_item_count, SUBSTR(last_error, 1, 300) AS err FROM srh_search_sync_states WHERE name = ?', [$name]);
        $scope = ['state' => $name];
        $s = $rows[0] ?? null;
        if (! $s) {
            return CheckResult::single(Observation::violation("在庫差分更新の同期状態（{$name}）がありません。", $scope, null, [], [], 'SEARCH_STATE_MISSING'));
        }
        $age = $s->last_success_at ? (int) \Carbon\CarbonImmutable::parse($s->last_success_at, 'Asia/Tokyo')->diffInMinutes($ctx->nowLocal()) : null;
        $metrics = ['minutes_since_success' => $age, 'updated' => (int) $s->last_updated_item_count];
        if ($age === null || $age > (int) $ctx->config('error_minutes', 90) || ! in_array(strtolower((string) $s->status), ['success', 'running'], true)) {
            return CheckResult::single(Observation::violation('在庫差分更新が止まっています（最終成功 '.($age === null ? 'なし' : $age.' 分前').'、状態 '.$s->status.'）。', $scope, null, $metrics, ['error' => $s->err], 'SEARCH_STOCK_SYNC_STALE'));
        }
        if ($age > (int) $ctx->config('warning_minutes', 45)) {
            return CheckResult::single(Observation::warning("在庫差分更新が {$age} 分間成功していません。", $scope, null, $metrics, [], 'SEARCH_STOCK_SYNC_DELAY'));
        }

        return CheckResult::single(Observation::healthy("在庫差分更新は正常（最終成功 {$age} 分前）。", $scope, null, $metrics));
    }
}
