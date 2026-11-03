<?php

namespace App\Monitoring\Checks\Delivery;

use App\Monitoring\Checks\BusinessCheck;
use App\Monitoring\Contracts\CheckDefinition;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\CheckResult;
use App\Monitoring\Services\Observation;

/**
 * delivery.external_sync: 飛脚 → 勘定衆 への集金（DEPOSIT）・容器回収（EMPTY_CONTAINER_PICKUP）連携。
 * 本番では毎日 02:00 台に前日完了分を一括連携（external_collaboration_data, partner=MOBILE_COLLECT, data_method=API）。
 * - 前日に完了した運行があるのに、当日 02:00＋猶予までに MOBILE_COLLECT の連携が 1 件も無い → ERROR
 * - 運行が多い（min_ops_for_deposit 以上）のに DEPOSIT が無い → WARNING（現金回収の無い日はあり得るため）
 * - 当日分に VALIDATION_ERROR / APPLY_ERROR → ERROR、failed_count>0 → WARNING
 */
class ExternalSyncCheck extends BusinessCheck
{
    public function definition(): CheckDefinition
    {
        return new CheckDefinition('delivery.external_sync', 'DELIVERY', '勘定衆への送信（集金・容器回収）', '前日分の集金・容器回収データが勘定衆へ連携されたか', 1, 900, 900, 'RB-17', false, true, 1, true,
            ['slots' => ['02:00'], 'grace_minutes' => 30, 'partner' => 'MOBILE_COLLECT', 'min_ops_for_deposit' => 5], ['slots', 'grace_minutes', 'min_ops_for_deposit']);
    }

    public function evaluate(CheckContext $ctx): CheckResult
    {
        $src = $ctx->source();
        $slots = (array) $ctx->config('slots', ['02:00']);
        $grace = (int) $ctx->config('grace_minutes', 30);
        $partner = (string) $ctx->config('partner', 'MOBILE_COLLECT');
        $obs = [];
        foreach ($this->dueSlots($ctx, $slots, $grace) as $slot) {
            $opsDate = \Carbon\CarbonImmutable::parse($slot['date'], 'Asia/Tokyo')->subDay();
            $ops = (int) $src->scalar("SELECT COUNT(*) FROM dlv_operations WHERE status = 'FINISHED' AND deleted_at IS NULL AND finished_at >= ? AND finished_at < ?",
                [$opsDate->format('Y-m-d 00:00:00'), $opsDate->addDay()->format('Y-m-d 00:00:00')], 0);
            $rows = $src->select('SELECT edi_type, status, COUNT(*) AS c, SUM(failed_count) AS failed, SUM(total_lines) AS lines_total FROM external_collaboration_data WHERE external_collaboration_partner = ? AND created_at >= ? AND created_at < ? GROUP BY edi_type, status',
                [$partner, $slot['start']->subMinutes(30)->format('Y-m-d H:i:s'), $slot['start']->addHours(22)->format('Y-m-d H:i:s')]);
            $byType = [];
            $errors = [];
            $failedLines = 0;
            foreach ($rows as $r) {
                $byType[$r->edi_type] = ($byType[$r->edi_type] ?? 0) + (int) $r->c;
                if (in_array($r->status, ['VALIDATION_ERROR', 'APPLY_ERROR'], true)) {
                    $errors[$r->edi_type.'/'.$r->status] = (int) $r->c;
                }
                $failedLines += (int) $r->failed;
            }
            $scope = ['business_date' => $opsDate->toDateString()];
            $metrics = ['finished_operations' => $ops, 'deposit' => $byType['DEPOSIT'] ?? 0, 'container_pickup' => $byType['EMPTY_CONTAINER_PICKUP'] ?? 0, 'failed_lines' => $failedLines];
            if ($errors) {
                $obs[] = Observation::violation("勘定衆への連携（{$opsDate->toDateString()} 分）でエラーがあります（".implode(', ', array_keys($errors)).'）。', $scope, $opsDate->toDateString(), $metrics, ['errors' => $errors], 'DELIVERY_EXTERNAL_ERROR');
            } elseif ($ops > 0 && ! $byType) {
                $obs[] = Observation::violation("前日（{$opsDate->toDateString()}）に完了した運行 {$ops} 件の集金・容器回収データが、{$slot['deadline']->format('H:i')} までに勘定衆へ連携されていません。", $scope, $opsDate->toDateString(), $metrics, [], 'DELIVERY_EXTERNAL_MISSING');
            } elseif ($ops >= (int) $ctx->config('min_ops_for_deposit', 5) && empty($byType['DEPOSIT'])) {
                $obs[] = Observation::warning("前日（{$opsDate->toDateString()}）は運行 {$ops} 件だが、集金（DEPOSIT）の連携がありません。", $scope, $opsDate->toDateString(), $metrics, [], 'DELIVERY_EXTERNAL_NO_DEPOSIT');
            } elseif ($failedLines > 0) {
                $obs[] = Observation::warning("勘定衆への連携（{$opsDate->toDateString()} 分）で失敗行が {$failedLines} 件あります。", $scope, $opsDate->toDateString(), $metrics, [], 'DELIVERY_EXTERNAL_FAILED_LINES');
            } else {
                $obs[] = Observation::healthy($ops > 0 ? "前日（{$opsDate->toDateString()}）分の連携は完了（集金 ".($byType['DEPOSIT'] ?? 0).' / 容器回収 '.($byType['EMPTY_CONTAINER_PICKUP'] ?? 0).'）。' : "前日（{$opsDate->toDateString()}）は完了した運行がありません（対象なし）。", $scope, $opsDate->toDateString(), $metrics);
            }
        }
        if (! $obs) {
            $obs[] = Observation::notDue('本日の連携確認の判定時刻前です。', ['slot' => 'next']);
        }

        return new CheckResult($obs);
    }
}
