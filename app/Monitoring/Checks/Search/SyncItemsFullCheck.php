<?php

namespace App\Monitoring\Checks\Search;

use App\Monitoring\Checks\BusinessCheck;
use App\Monitoring\Contracts\CheckDefinition;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\CheckResult;
use App\Monitoring\Services\Observation;

/**
 * search.sync_items_full: 検索データの全件更新（srh_search_sync_states, name=searchable_items_full、毎日 03:00 頃）。
 * 判定時刻（既定 04:00）以降に当日の last_success_at が無い、または status がエラー → ERROR。
 */
class SyncItemsFullCheck extends BusinessCheck
{
    public function definition(): CheckDefinition
    {
        return new CheckDefinition('search.sync_items_full', 'SEARCH', '検索データの全件更新', '毎日の全件更新が完了しているか', 1, 900, 900, 'RB-16', false, true, 1, true,
            ['state_name' => 'searchable_items_full', 'slots' => ['03:00'], 'grace_minutes' => 60], ['slots', 'grace_minutes']);
    }

    public function evaluate(CheckContext $ctx): CheckResult
    {
        $name = (string) $ctx->config('state_name', 'searchable_items_full');
        $rows = $ctx->source()->select('SELECT name, status, last_started_at, last_success_at, last_finished_at, last_checked_item_count, last_updated_item_count, SUBSTR(last_error, 1, 300) AS err FROM srh_search_sync_states WHERE name = ?', [$name]);
        $s = $rows[0] ?? null;
        $slots = (array) $ctx->config('slots', ['03:00']);
        $grace = (int) $ctx->config('grace_minutes', 60);
        $obs = [];
        foreach ($this->dueSlots($ctx, $slots, $grace) as $slot) {
            $scope = ['business_date' => $slot['date']];
            if (! $s) {
                $obs[] = Observation::violation("検索データの同期状態（{$name}）がありません。", $scope, $slot['date'], [], [], 'SEARCH_STATE_MISSING');

                continue;
            }
            $success = $s->last_success_at ? \Carbon\CarbonImmutable::parse($s->last_success_at, 'Asia/Tokyo') : null;
            $metrics = ['checked' => (int) $s->last_checked_item_count, 'updated' => (int) $s->last_updated_item_count];
            if ($success && $success->greaterThanOrEqualTo($slot['start']->subMinutes(30))) {
                $obs[] = Observation::healthy("検索データの全件更新 {$slot['date']} は完了（".$success->format('H:i').'）。', $scope, $slot['date'], $metrics);
            } else {
                $why = strtolower((string) $s->status) !== 'success' ? "状態 {$s->status}" : '最終成功 '.($success?->format('m/d H:i') ?? 'なし');
                $obs[] = Observation::violation("検索データの全件更新 {$slot['date']} が {$slot['deadline']->format('H:i')} までに完了していません（{$why}）。", $scope, $slot['date'], $metrics, ['error' => $s->err], 'SEARCH_FULL_SYNC_MISSING');
            }
        }
        if (! $obs) {
            $obs[] = Observation::notDue('本日の全件更新の判定時刻前です。', ['slot' => 'next']);
        }

        return new CheckResult($obs);
    }
}
