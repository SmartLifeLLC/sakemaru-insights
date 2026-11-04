<?php

namespace App\Monitoring\Checks\Core;

use App\Monitoring\Checks\Monitor\ActiveScopes;
use App\Monitoring\Services\SourceReader;
use Carbon\CarbonImmutable;

/**
 * 勘定衆の統計処理の判定で共通に使う部品（仕様 v2.3）。Core 側の変更なしで、stats_import_runs（対象日ごとの子記録）と
 * 売上伝票（earnings）の更新日時から判定する。業務データは変更しない。
 * 未解決案件は判定範囲（直近 26 時間）を外れても同じ対象で再確認を続ける（継続追跡）。
 */
trait CoreJobRuns
{
    use ActiveScopes;

    /** 予定枠ごとの更新窓（時間）。Core HanaSchedule の --previous_boundary と一致させる */
    public const REFRESH_BOUNDARY_HOURS = ['02:00' => 30, '07:00' => 5, '08:10' => 6, '09:00' => 6, '12:00' => 6, '16:00' => 6, '19:00' => 6];

    /** 未解決案件の scope 一覧（継続追跡用） @return array<int,array<string,mixed>> */
    protected function trackedScopes(string $checkKey): array
    {
        $out = [];
        foreach (\App\Models\Monitoring\Incident::query()->where('check_key', $checkKey)->where('observation_state', \App\Monitoring\Enums\ObservationState::ACTIVE->value)->limit(200)->pluck('scope_json') as $scope) {
            $out[] = is_array($scope) ? $scope : (array) json_decode((string) $scope, true);
        }

        return $out;
    }

    /** JST の naive 日時文字列 → CarbonImmutable(JST) */
    protected function localTime(?string $value): ?CarbonImmutable
    {
        return $value ? CarbonImmutable::parse($value, 'Asia/Tokyo') : null;
    }

    /**
     * 更新窓 [boundary, slotStart) に更新された有効な売上伝票の計上日（Core の対象抽出の近似。無効伝票・トレード側の更新は見ない）。
     *
     * @return array<string,int> 計上日 => 件数
     */
    protected function updatedAccountDates(SourceReader $src, CarbonImmutable $boundary, CarbonImmutable $slotStart): array
    {
        $rows = $src->select('SELECT account_date AS d, COUNT(*) AS c FROM earnings WHERE updated_at >= ? AND updated_at < ? AND is_active = 1 GROUP BY account_date ORDER BY account_date LIMIT 62', [$boundary->format('Y-m-d H:i:s'), $slotStart->format('Y-m-d H:i:s')]);
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->d] = (int) $r->c;
        }

        return $out;
    }

    /** 指定の計上日のうち、since 以降に daily_aggregate が成功した日 @return string[] */
    protected function succeededDatesSince(SourceReader $src, array $dates, CarbonImmutable $since): array
    {
        if ($dates === []) {
            return [];
        }
        $rows = $src->select("SELECT DISTINCT period_from AS d FROM stats_import_runs WHERE job_type = 'daily_aggregate' AND source_system = 'core_earning' AND status = 'succeeded' AND period_from IN (".implode(',', array_fill(0, count($dates), '?')).') AND started_at >= ?',
            array_merge($dates, [$since->subMinutes(2)->format('Y-m-d H:i:s')]));

        return array_map(fn ($r) => (string) $r->d, $rows);
    }

    /** 枠の子記録（daily_aggregate / daily_department_sync） @return array<int,object> */
    protected function childRuns(SourceReader $src, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $src->select("SELECT job_type, source_system, period_from AS d, status, started_at, finished_at, SUBSTR(error_message, 1, 300) AS err FROM stats_import_runs WHERE source_system = 'core_earning' AND job_type IN ('daily_aggregate', 'daily_department_sync') AND started_at >= ? AND started_at < ? ORDER BY id", [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')]);
    }
}
