<?php

namespace App\Monitoring\Checks\Delivery;

use App\Monitoring\Checks\BusinessCheck;
use App\Monitoring\Contracts\CheckDefinition;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\CheckResult;
use App\Monitoring\Services\Observation;

/**
 * delivery.container_collect: 容器回収データの到着。前日の配送（dlv_item_deliveries.issue_date）が一定数以上あるのに、
 * 同日の容器回収（dlv_empty_container_collects.issue_date）が 0 件 → WARNING（確定根拠ではないため ERROR にしない）。
 */
class ContainerCollectCheck extends BusinessCheck
{
    public function definition(): CheckDefinition
    {
        return new CheckDefinition('delivery.container_collect', 'DELIVERY', '容器回収データの到着', '配送があった日に容器回収データが届いているか', 1, 3600, 3600, 'RB-17', false, true, 1, true,
            ['min_deliveries' => 10], ['min_deliveries']);
    }

    public function evaluate(CheckContext $ctx): CheckResult
    {
        $src = $ctx->source();
        $date = $ctx->nowLocal()->subDay()->toDateString();
        $deliveries = (int) $src->scalar('SELECT COUNT(*) FROM dlv_item_deliveries WHERE issue_date = ? AND deleted_at IS NULL', [$date], 0);
        $collects = (int) $src->scalar('SELECT COUNT(*) FROM dlv_empty_container_collects WHERE issue_date = ? AND deleted_at IS NULL', [$date], 0);
        $scope = ['business_date' => $date];
        $metrics = ['deliveries' => $deliveries, 'container_collects' => $collects];
        if ($deliveries >= (int) $ctx->config('min_deliveries', 10) && $collects === 0) {
            return CheckResult::single(Observation::warning("{$date} は配送 {$deliveries} 件だが、容器回収データがありません。", $scope, $date, $metrics, [], 'CONTAINER_COLLECT_MISSING'));
        }

        return CheckResult::single(Observation::healthy($deliveries > 0 ? "{$date} の容器回収データ {$collects} 件（配送 {$deliveries} 件）。" : "{$date} は配送がありません（対象なし）。", $scope, $date, $metrics));
    }
}
