<?php

namespace App\Monitoring\Checks\Core;

use App\Monitoring\Checks\BusinessCheck;
use App\Monitoring\Contracts\CheckDefinition;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\CheckResult;
use App\Monitoring\Services\Observation;
use Carbon\CarbonImmutable;

/**
 * core.stats_job_slot: 更新分再集計の予定枠（02:00 / 07:00 / 08:10 / 09:00 / 12:00 / 16:00 / 19:00）の実行確認（仕様 v2.3、Core 変更なし版）。
 * - 枠＋開始猶予に子記録（daily_aggregate）が無く、更新窓に有効な売上伝票の更新がある → STATS_JOB_NOT_STARTED（ERROR）
 * - 更新が無ければ「対象なし（推定）」として正常ログ
 * - 子記録が running のまま完了期限を超える → STATS_JOB_OVERRUN、failed → STATS_JOB_FAILED
 * 指紋は枠（日付＋時刻）で固定し、同じ枠の完了でのみ復旧。未解決は判定範囲を外れても再確認する。
 */
class StatsJobSlotCheck extends BusinessCheck
{
    use CoreJobRuns;

    public function definition(): CheckDefinition
    {
        return new CheckDefinition('core.stats_job_slot', 'CORE', '統計の定時処理（予定枠）', '予定枠ごとに更新分再集計が起動・完了したか（起動なし・長時間実行・失敗）', 1, 300, 300, 'RB-22', false, true, 1, true,
            ['slots' => array_keys(self::REFRESH_BOUNDARY_HOURS), 'start_grace_minutes' => 5, 'complete_minutes' => 30, 'default_boundary_hours' => 6],
            ['slots', 'start_grace_minutes', 'complete_minutes', 'default_boundary_hours'],
            '予定枠と更新窓（--previous_boundary）は Core の HanaSchedule と一致させる。対象日は売上伝票の更新から推定（Core の記録が無いため近似）');
    }

    public function evaluate(CheckContext $ctx): CheckResult
    {
        $slots = (array) $ctx->config('slots', array_keys(self::REFRESH_BOUNDARY_HOURS));
        $startGrace = (int) $ctx->config('start_grace_minutes', 5);
        $obs = [];
        $seen = [];
        foreach ($this->dueSlots($ctx, $slots, $startGrace) as $slot) {
            $seen[$slot['date'].' '.$slot['time']] = true;
            $obs[] = $this->evaluateSlot($ctx, $slot['date'], $slot['time'], $slot['start']);
        }
        foreach ($this->trackedScopes('core.stats_job_slot') as $scope) {
            $date = (string) ($scope['business_date'] ?? '');
            $time = (string) ($scope['slot'] ?? '');
            if ($date === '' || $time === '' || isset($seen[$date.' '.$time]) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }
            $obs[] = $this->evaluateSlot($ctx, $date, $time, CarbonImmutable::parse($date.' '.$time, 'Asia/Tokyo'));
        }
        if (! $obs) {
            $next = $this->nextSlot($ctx, $slots, $startGrace);
            $obs[] = Observation::notDue('次の判定は '.($next ? $next['date'].' '.$next['time'].'＋'.$startGrace.'分' : '—').'。', ['slot' => 'next']);
        }

        return new CheckResult($obs);
    }

    private function evaluateSlot(CheckContext $ctx, string $date, string $time, CarbonImmutable $start): Observation
    {
        $src = $ctx->source();
        $now = $ctx->nowLocal();
        $scope = ['business_date' => $date, 'slot' => $time];
        $startGrace = (int) $ctx->config('start_grace_minutes', 5);
        $deadline = $start->addMinutes((int) $ctx->config('complete_minutes', 30));
        $boundaryHours = (int) (self::REFRESH_BOUNDARY_HOURS[$time] ?? $ctx->config('default_boundary_hours', 6));
        $runs = $this->childRuns($src, $start->subMinutes(2), $deadline->addMinutes($startGrace));
        $aggregate = array_values(array_filter($runs, fn ($r) => $r->job_type === 'daily_aggregate'));
        $label = "更新分の再集計（{$date} {$time}）";
        if ($aggregate === []) {
            if ($now->lessThan($start->addMinutes($startGrace))) {
                return Observation::notDue("{$label}は開始待ち。", $scope, $date);
            }
            $updated = $this->updatedAccountDates($src, $start->subHours($boundaryHours), $start);
            $metrics = ['updated_slips' => array_sum($updated), 'updated_dates' => count($updated)];
            if ($updated === []) {
                return Observation::healthy("{$label}: 更新窓（{$boundaryHours} 時間）に有効な売上伝票の更新が無いため、対象なし（推定）。", $scope, $date, $metrics);
            }
            // 枠では起動していない。後続の枠（または手動）で対象日がすべて処理されていれば解消
            $later = $this->succeededDatesSince($src, array_keys($updated), $start);
            $missing = array_values(array_diff(array_keys($updated), $later));
            if ($missing === []) {
                return Observation::healthy("{$label}は予定枠では起動していませんが、対象 ".count($updated).' 日分は後続の処理で集計済み。', $scope, $date, $metrics);
            }

            return Observation::violation("{$label}が予定＋{$startGrace}分を過ぎても起動していません（更新窓に ".count($updated).' 日分・'.array_sum($updated).' 件の伝票更新あり、未集計: '.implode(', ', $missing).'）。cron 停止・前の枠の実行中（withoutOverlapping）・デプロイ中を確認してください。', $scope, $date, $metrics + ['missing_dates' => count($missing)], ['dates' => array_keys($updated)], 'STATS_JOB_NOT_STARTED');
        }
        $failed = array_values(array_filter($aggregate, fn ($r) => $r->status === 'failed'));
        $running = array_values(array_filter($aggregate, fn ($r) => $r->status === 'running'));
        $metrics = ['dates' => count($aggregate), 'failed' => count($failed), 'running' => count($running)];
        if ($failed !== []) {
            return Observation::violation("{$label}で ".count($failed).' 日分が失敗しました（'.implode(', ', array_map(fn ($r) => $r->d, $failed)).'）: '.($failed[0]->err ?: '詳細なし'), $scope, $date, $metrics, ['error' => $failed[0]->err], 'STATS_JOB_FAILED');
        }
        if ($running !== [] && $now->greaterThan($deadline)) {
            $first = $this->localTime($running[0]->started_at) ?? $start;

            return Observation::violation("{$label}が期限（{$deadline->format('H:i')}）を過ぎても終了していません（{$running[0]->d} が ".(int) $first->diffInMinutes($now).' 分間 running）。停止・ロック待ち・長時間実行を確認してください。', $scope, $date, $metrics, [], 'STATS_JOB_OVERRUN');
        }
        if ($running !== []) {
            return Observation::healthy("{$label}は実行中（".count($aggregate).' 日分、未完了 '.count($running).'）。', $scope, $date, $metrics);
        }

        return Observation::healthy("{$label}は完了（".count($aggregate).' 日分）。', $scope, $date, $metrics);
    }
}
