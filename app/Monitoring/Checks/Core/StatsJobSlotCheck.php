<?php

namespace App\Monitoring\Checks\Core;

use App\Monitoring\Checks\BusinessCheck;
use App\Monitoring\Contracts\CheckDefinition;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\CheckResult;
use App\Monitoring\Services\Observation;
use Carbon\CarbonImmutable;

/**
 * core.stats_job_slot: 予定枠ごとの親処理（更新分再集計・前日確定・部門同期・月次）の実行確認（仕様 v2.3 第3章）。
 * 開始記録なし（予定＋開始猶予）→ STATS_JOB_NOT_STARTED / 完了期限を過ぎて running → STATS_JOB_OVERRUN / failed → STATS_JOB_FAILED。
 * skipped（対象 0 件・他ノード）は正常ログ。lock_busy が続けば WARNING。指紋はジョブ＋予定枠で固定し、同じ枠の完了でのみ復旧する。
 */
class StatsJobSlotCheck extends BusinessCheck
{
    use CoreJobRuns;

    public const JOBS = [
        'stats.refresh_updated' => ['slots' => 'slots_refresh', 'complete' => 'complete_refresh_minutes', 'deadline' => null],
        'stats.finalize_daily' => ['slots' => 'slots_finalize', 'complete' => null, 'deadline' => 'deadline_finalize_time'],
        'stats.sync_departments' => ['slots' => 'slots_sync', 'complete' => null, 'deadline' => 'deadline_sync_time'],
        'stats.refresh_monthly' => ['slots' => 'slots_monthly', 'complete' => 'complete_monthly_minutes', 'deadline' => null],
    ];

    public function definition(): CheckDefinition
    {
        return new CheckDefinition('core.stats_job_slot', 'CORE', '統計の定時処理（予定枠）', '予定枠ごとに親処理が起動・完了したか（起動なし・長時間実行・失敗）', 1, 300, 300, 'RB-22', false, true, 1, true,
            [
                'slots_refresh' => ['02:00', '07:00', '08:10', '09:00', '12:00', '16:00', '19:00'], 'complete_refresh_minutes' => 30,
                'slots_finalize' => ['02:00'], 'deadline_finalize_time' => '02:45',
                'slots_sync' => ['02:20'], 'deadline_sync_time' => '02:45',
                'slots_monthly' => ['15:00'], 'complete_monthly_minutes' => 60,
                'start_grace_minutes' => 5, 'lock_busy_warn_count' => 2,
            ],
            ['slots_refresh', 'complete_refresh_minutes', 'slots_finalize', 'deadline_finalize_time', 'slots_sync', 'deadline_sync_time', 'slots_monthly', 'complete_monthly_minutes', 'start_grace_minutes', 'lock_busy_warn_count'],
            '予定枠は Core の HanaSchedule / config/scheduled_jobs.php と一致させる');
    }

    public function evaluate(CheckContext $ctx): CheckResult
    {
        $src = $ctx->source();
        $now = $ctx->nowLocal();
        $startGrace = (int) $ctx->config('start_grace_minutes', 5);
        $obs = [];
        $seen = [];
        try {
            foreach (self::JOBS as $job => $keys) {
                $slots = (array) $ctx->config($keys['slots'], []);
                foreach ($this->dueSlots($ctx, $slots, $startGrace) as $slot) {
                    $seen[$job.'|'.$slot['date'].' '.$slot['time']] = true;
                    $obs[] = $this->evaluateSlot($ctx, $job, $slot['date'], $slot['time'], $slot['start'], $startGrace);
                }
            }
            // 継続追跡: 判定範囲を外れた未解決案件の対象も再確認する
            foreach ($this->trackedScopes('core.stats_job_slot') as $scope) {
                $job = (string) ($scope['job'] ?? '');
                $date = (string) ($scope['business_date'] ?? '');
                $time = (string) ($scope['slot'] ?? '');
                if (! isset(self::JOBS[$job]) || $date === '' || $time === '' || isset($seen[$job.'|'.$date.' '.$time])) {
                    continue;
                }
                $obs[] = $this->evaluateSlot($ctx, $job, $date, $time, CarbonImmutable::parse($date.' '.$time, 'Asia/Tokyo'), $startGrace);
            }
        } catch (\Throwable $e) {
            if ($this->isTableMissing($e)) {
                return $this->notInstalled($ctx);
            }
            throw $e;
        }
        if (! $obs) {
            $nexts = [];
            foreach (self::JOBS as $job => $keys) {
                if ($n = $this->nextSlot($ctx, (array) $ctx->config($keys['slots'], []), $startGrace)) {
                    $nexts[] = $n['start'];
                }
            }
            sort($nexts);
            $obs[] = Observation::notDue('次の判定は '.($nexts ? $nexts[0]->format('m/d H:i') : '—').'＋'.$startGrace.'分。', ['slot' => 'next']);
        }

        return new CheckResult($obs);
    }

    private function evaluateSlot(CheckContext $ctx, string $job, string $date, string $time, CarbonImmutable $start, int $startGrace): Observation
    {
        $now = $ctx->nowLocal();
        $keys = self::JOBS[$job];
        $label = $this->jobLabel($job);
        $scope = ['job' => $job, 'business_date' => $date, 'slot' => $time];
        $slotKey = $date.' '.$time;
        $deadline = $keys['deadline']
            ? CarbonImmutable::parse($date.' '.$ctx->config($keys['deadline'], '02:45'), 'Asia/Tokyo')
            : $start->addMinutes((int) $ctx->config($keys['complete'], 30));
        if ($deadline->lessThanOrEqualTo($start)) {
            $deadline = $deadline->addDay(); // 日付をまたぐ期限（例 23:50 開始・00:30 期限）
        }
        $runs = $this->runsForSlot($ctx->source(), $job, $slotKey);
        $metrics = ['runs' => count($runs)];
        if ($runs === []) {
            if ($now->lessThan($start->addMinutes($startGrace))) {
                return Observation::notDue("{$label} {$slotKey} は開始待ち。", $scope, $date);
            }

            return Observation::violation("{$label}（予定 {$slotKey}）が予定＋{$startGrace}分を過ぎても起動していません。cron 停止・前の枠の実行中（withoutOverlapping）・デプロイ中を確認してください。", $scope, $date, $metrics, [], 'STATS_JOB_NOT_STARTED');
        }
        $last = end($runs);
        $metrics += ['target_count' => (int) $last->target_count, 'node' => $last->node];
        switch ($last->status) {
            case 'succeeded':
                return Observation::healthy("{$label}（{$slotKey}）は {$last->node} で完了（対象 {$last->target_count} 日）。", $scope, $date, $metrics);
            case 'skipped':
                $lockBusy = count(array_filter($runs, fn ($r) => $r->status === 'skipped' && $r->skip_reason === 'lock_busy'));
                if ($last->skip_reason === 'lock_busy' && $lockBusy >= (int) $ctx->config('lock_busy_warn_count', 2)) {
                    return Observation::warning("{$label}（{$slotKey}）がロック待ちで {$lockBusy} 回見送られています。前の処理が長引いていないか確認してください。", $scope, $date, $metrics, [], 'STATS_JOB_LOCK_BUSY');
                }

                return Observation::healthy("{$label}（{$slotKey}）は正常に起動し、".($last->skip_reason === 'no_targets' ? '対象 0 件のため処理なし' : '見送り（'.$last->skip_reason.'）').'。', $scope, $date, $metrics);
            case 'failed':
                return Observation::violation("{$label}（{$slotKey}）が失敗しました（終了コード {$last->exit_code}）: ".($last->err ?: '詳細なし'), $scope, $date, $metrics, ['error' => $last->err], 'STATS_JOB_FAILED');
            default: // running
                $started = $this->localTime($last->started_at) ?? $start;
                $elapsed = (int) $started->diffInMinutes($now);
                if ($now->greaterThan($deadline)) {
                    return Observation::violation("{$label}（{$slotKey}）が {$last->node} で {$elapsed} 分間終了していません（期限 {$deadline->format('H:i')}）。停止・ロック待ち・長時間実行を確認してください。", $scope, $date, $metrics + ['elapsed_minutes' => $elapsed], [], 'STATS_JOB_OVERRUN');
                }

                return Observation::healthy("{$label}（{$slotKey}）は {$last->node} で実行中（{$elapsed} 分）。", $scope, $date, $metrics + ['elapsed_minutes' => $elapsed]);
        }
    }
}
