<?php

namespace App\Monitoring\Checks\Core;

use App\Monitoring\Checks\BusinessCheck;
use App\Monitoring\Contracts\CheckDefinition;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\CheckResult;
use App\Monitoring\Services\Observation;

/**
 * core.stats_job_dates: 親処理が開始時に確定した対象日ごとの完了確認（仕様 v2.3）。
 * 対象日の daily_aggregate 成功が親開始以降に無い → STATS_DATE_INCOMPLETE。sync_departments 指定の枠で部門同期が無い → STATS_DEPT_SYNC_INCOMPLETE。
 * 指紋は「ジョブ＋対象日（＋種別）」。同じ対象日の後続の成功で復旧し、別の日付の成功では解消しない。
 */
class StatsJobDatesCheck extends BusinessCheck
{
    use CoreJobRuns;

    public function definition(): CheckDefinition
    {
        return new CheckDefinition('core.stats_job_dates', 'CORE', '統計の対象日別の完了', '親処理が決めた対象日のうち、集計・部門同期が成功していない日がないか', 1, 600, 600, 'RB-22', false, true, 1, true,
            ['settle_minutes' => 10, 'lookback_hours' => 26, 'max_dates_per_run' => 31], ['settle_minutes', 'lookback_hours', 'max_dates_per_run']);
    }

    public function evaluate(CheckContext $ctx): CheckResult
    {
        $src = $ctx->source();
        $now = $ctx->nowLocal();
        $settle = (int) $ctx->config('settle_minutes', 10);
        $maxDates = (int) $ctx->config('max_dates_per_run', 31);
        $obs = [];
        $targets = []; // key => [job, date, kind, since(local), run]
        try {
            foreach (['stats.refresh_updated', 'stats.sync_departments'] as $job) {
                foreach ($this->recentRuns($src, $job, $now->subHours((int) $ctx->config('lookback_hours', 26))) as $run) {
                    if (! in_array($run->status, ['succeeded', 'failed'], true)) {
                        continue;
                    }
                    $finished = $this->localTime($run->finished_at);
                    if ($finished && $finished->addMinutes($settle)->greaterThan($now)) {
                        continue; // 完了直後は子記録の反映を待つ
                    }
                    $dates = array_slice((array) json_decode((string) $run->target_dates_json, true), 0, $maxDates);
                    $opts = (array) json_decode((string) ($run->options_json ?? '[]'), true);
                    foreach ($dates as $d) {
                        $kinds = $job === 'stats.sync_departments' ? ['dept'] : (! empty($opts['sync_departments']) ? ['aggregate', 'dept'] : ['aggregate']);
                        foreach ($kinds as $kind) {
                            $k = $job.'|'.$d.'|'.$kind;
                            // 同じ対象は最新の親処理で判定する
                            $targets[$k] = ['job' => $job, 'date' => $d, 'kind' => $kind, 'since' => $this->localTime($run->started_at) ?? $now, 'slot' => $run->slot_key];
                        }
                    }
                }
            }
            foreach ($this->trackedScopes('core.stats_job_dates') as $scope) {
                $k = ($scope['job'] ?? '').'|'.($scope['business_date'] ?? '').'|'.($scope['kind'] ?? '');
                if (! isset($targets[$k]) && ! empty($scope['job']) && ! empty($scope['business_date'])) {
                    $targets[$k] = ['job' => $scope['job'], 'date' => $scope['business_date'], 'kind' => $scope['kind'] ?? 'aggregate', 'since' => null, 'slot' => $scope['slot'] ?? null];
                }
            }
            // 対象日ごとの子記録（daily_aggregate / daily_department_sync）の成功を 1 クエリでまとめて取得
            $byKind = ['aggregate' => 'daily_aggregate', 'dept' => 'daily_department_sync'];
            foreach ($byKind as $kind => $jobType) {
                $dates = array_values(array_unique(array_map(fn ($t) => $t['date'], array_filter($targets, fn ($t) => $t['kind'] === $kind))));
                if ($dates === []) {
                    continue;
                }
                $minSince = min(array_map(fn ($t) => $t['since'] ?? $now->subDays(3), array_filter($targets, fn ($t) => $t['kind'] === $kind)));
                $rows = $src->select('SELECT period_from AS d, MAX(started_at) AS last_ok FROM stats_import_runs WHERE job_type = ? AND status = ? AND period_from IN ('.implode(',', array_fill(0, count($dates), '?')).') AND started_at >= ? GROUP BY period_from',
                    array_merge([$jobType, 'succeeded'], $dates, [$minSince->subMinute()->format('Y-m-d H:i:s')]));
                $ok = [];
                foreach ($rows as $r) {
                    $ok[(string) $r->d] = $this->localTime($r->last_ok);
                }
                foreach ($targets as $t) {
                    if ($t['kind'] !== $kind) {
                        continue;
                    }
                    $scope = ['job' => $t['job'], 'business_date' => $t['date'], 'kind' => $kind];
                    $label = $this->jobLabel($t['job']);
                    $what = $kind === 'dept' ? '部門統計への同期' : '日次集計';
                    $done = isset($ok[$t['date']]) && ($t['since'] === null || $ok[$t['date']]->greaterThanOrEqualTo($t['since']->subMinute()));
                    if ($done) {
                        $obs[] = Observation::healthy("{$label}: 対象日 {$t['date']} の{$what}は完了（".$ok[$t['date']]->format('m/d H:i').'）。', $scope, $t['date']);
                    } else {
                        $obs[] = Observation::violation("{$label}（{$t['slot']}）で対象になった {$t['date']} の{$what}が成功していません。他の日が成功していても、この日は未完了です。", $scope, $t['date'], [], [], $kind === 'dept' ? 'STATS_DEPT_SYNC_INCOMPLETE' : 'STATS_DATE_INCOMPLETE');
                    }
                }
            }
        } catch (\Throwable $e) {
            if ($this->isTableMissing($e)) {
                return $this->notInstalled($ctx);
            }
            throw $e;
        }
        if (! $obs) {
            $obs[] = Observation::notDue('直近の親処理に確認対象の対象日はありません。', ['slot' => 'none']);
        }

        return new CheckResult($obs);
    }
}
