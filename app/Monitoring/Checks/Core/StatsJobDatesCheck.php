<?php

namespace App\Monitoring\Checks\Core;

use App\Monitoring\Checks\BusinessCheck;
use App\Monitoring\Contracts\CheckDefinition;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\CheckResult;
use App\Monitoring\Services\Observation;

/**
 * core.stats_job_dates: 対象日別の集計・部門同期の完了（仕様 v2.3、Core 変更なし版 = 「更新対象の抽出漏れ疑い」）。
 * 予定枠の更新窓に有効な売上伝票の更新があった計上日ごとに、枠の開始以降の daily_aggregate 成功（02:00 以外は daily_department_sync も）を確認する。
 * Core の対象抽出は設定依存で完全には再現できないため WARNING（STATS_DATE_SUSPECT_MISSING / STATS_DEPT_SYNC_SUSPECT_MISSING）。
 * 指紋は対象日（＋種別）。同じ対象日の後続の成功で復旧し、別の日付の成功では解消しない。
 */
class StatsJobDatesCheck extends BusinessCheck
{
    use CoreJobRuns;

    public function definition(): CheckDefinition
    {
        return new CheckDefinition('core.stats_job_dates', 'CORE', '統計の対象日別の完了（抽出漏れ疑い）', '更新のあった計上日ごとに集計・部門同期が成功しているか（取消・計上日変更の再集計漏れを含む）', 1, 600, 600, 'RB-22', false, true, 1, true,
            ['slots' => array_keys(self::REFRESH_BOUNDARY_HOURS), 'settle_minutes' => 30, 'department_sync_except' => ['02:00'], 'default_boundary_hours' => 6],
            ['slots', 'settle_minutes', 'department_sync_except', 'default_boundary_hours']);
    }

    public function evaluate(CheckContext $ctx): CheckResult
    {
        $src = $ctx->source();
        $now = $ctx->nowLocal();
        $settle = (int) $ctx->config('settle_minutes', 30);
        $except = (array) $ctx->config('department_sync_except', ['02:00']);
        $obs = [];
        $targets = []; // "date|kind" => [date, kind, since, slot]
        foreach ($this->dueSlots($ctx, (array) $ctx->config('slots', []), $settle) as $slot) {
            $boundaryHours = (int) (self::REFRESH_BOUNDARY_HOURS[$slot['time']] ?? $ctx->config('default_boundary_hours', 6));
            foreach (array_keys($this->updatedAccountDates($src, $slot['start']->subHours($boundaryHours), $slot['start'])) as $d) {
                $kinds = in_array($slot['time'], $except, true) ? ['aggregate'] : ['aggregate', 'dept'];
                foreach ($kinds as $kind) {
                    $targets[$d.'|'.$kind] = ['date' => $d, 'kind' => $kind, 'since' => $slot['start'], 'slot' => $slot['date'].' '.$slot['time']];
                }
            }
        }
        foreach ($this->trackedScopes('core.stats_job_dates') as $scope) {
            $d = (string) ($scope['business_date'] ?? '');
            $kind = (string) ($scope['kind'] ?? 'aggregate');
            if ($d !== '' && ! isset($targets[$d.'|'.$kind])) {
                $targets[$d.'|'.$kind] = ['date' => $d, 'kind' => $kind, 'since' => null, 'slot' => $scope['slot'] ?? '—'];
            }
        }
        foreach (['aggregate' => 'daily_aggregate', 'dept' => 'daily_department_sync'] as $kind => $jobType) {
            $mine = array_filter($targets, fn ($t) => $t['kind'] === $kind);
            if ($mine === []) {
                continue;
            }
            $dates = array_values(array_unique(array_map(fn ($t) => $t['date'], $mine)));
            $since = min(array_map(fn ($t) => $t['since'] ?? $now->subDays(3), $mine));
            $rows = $src->select("SELECT period_from AS d, MAX(started_at) AS last_ok FROM stats_import_runs WHERE job_type = ? AND source_system = 'core_earning' AND status = 'succeeded' AND period_from IN (".implode(',', array_fill(0, count($dates), '?')).') AND started_at >= ? GROUP BY period_from',
                array_merge([$jobType], $dates, [$since->subMinutes(2)->format('Y-m-d H:i:s')]));
            $ok = [];
            foreach ($rows as $r) {
                $ok[(string) $r->d] = $this->localTime($r->last_ok);
            }
            foreach ($mine as $t) {
                $scope = ['business_date' => $t['date'], 'kind' => $kind];
                $what = $kind === 'dept' ? '部門統計への同期' : '日次集計';
                $done = isset($ok[$t['date']]) && ($t['since'] === null || $ok[$t['date']]->greaterThanOrEqualTo($t['since']->subMinutes(2)));
                if ($done) {
                    $obs[] = Observation::healthy("計上日 {$t['date']} の{$what}は完了（".$ok[$t['date']]->format('m/d H:i').'）。', $scope, $t['date']);
                } else {
                    $obs[] = Observation::warning("計上日 {$t['date']} の売上伝票が更新されましたが、予定枠（{$t['slot']}）以降に{$what}の成功がありません。再集計の抽出漏れ（取消・計上日変更）の疑い。他の日が成功していてもこの日は未完了です。", $scope, $t['date'], [], [], $kind === 'dept' ? 'STATS_DEPT_SYNC_SUSPECT_MISSING' : 'STATS_DATE_SUSPECT_MISSING');
                }
            }
        }
        if (! $obs) {
            $obs[] = Observation::notDue('直近の予定枠に確認対象の計上日はありません。', ['slot' => 'none']);
        }

        return new CheckResult($obs);
    }
}
