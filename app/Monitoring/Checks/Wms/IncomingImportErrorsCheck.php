<?php

namespace App\Monitoring\Checks\Wms;

use App\Monitoring\Checks\BusinessCheck;
use App\Monitoring\Contracts\CheckDefinition;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\CheckResult;
use App\Monitoring\Services\Observation;

/**
 * wms.incoming_import_errors: 入荷データ取込エラー（wms_incoming_import_errors）。
 * 本番では平常でも 1 日数百〜2 千件あるため、固定件数ではなく「前日件数が直近 7 日の中央値の倍率を超え、かつ下限件数以上」で WARNING。
 */
class IncomingImportErrorsCheck extends BusinessCheck
{
    public function definition(): CheckDefinition
    {
        return new CheckDefinition('wms.incoming_import_errors', 'WMS', '入荷データ取込エラーの急増', '前日の取込エラー件数が平常値から急増していないか', 1, 3600, 3600, 'RB-13', false, true, 1, true,
            ['ratio' => 2.0, 'min_count' => 1000], ['ratio', 'min_count']);
    }

    public function evaluate(CheckContext $ctx): CheckResult
    {
        $src = $ctx->source();
        $today = $ctx->nowLocal()->startOfDay();
        $rows = $src->select('SELECT DATE(created_at) AS d, COUNT(*) AS c FROM wms_incoming_import_errors WHERE created_at >= ? AND created_at < ? GROUP BY DATE(created_at)',
            [$today->subDays(8)->format('Y-m-d H:i:s'), $today->format('Y-m-d H:i:s')]);
        $by = [];
        foreach ($rows as $r) {
            $by[(string) $r->d] = (int) $r->c;
        }
        $y = $today->subDay()->toDateString();
        $yc = $by[$y] ?? 0;
        $prev = [];
        for ($i = 2; $i <= 8; $i++) {
            $prev[] = $by[$today->subDays($i)->toDateString()] ?? 0;
        }
        sort($prev);
        $median = $prev[intdiv(count($prev), 2)];
        $ratio = (float) $ctx->config('ratio', 2.0);
        $min = (int) $ctx->config('min_count', 1000);
        $scope = ['business_date' => $y];
        $metrics = ['yesterday' => $yc, 'median_7d' => $median];
        if ($yc >= $min && $yc > $median * $ratio) {
            $types = $src->select('SELECT error_type, COUNT(*) AS c FROM wms_incoming_import_errors WHERE created_at >= ? AND created_at < ? GROUP BY error_type ORDER BY c DESC LIMIT 5', [$today->subDay()->format('Y-m-d H:i:s'), $today->format('Y-m-d H:i:s')]);

            return CheckResult::single(Observation::warning("入荷データ取込エラーが急増しています（{$y}: {$yc} 件、直近中央値 {$median} 件）。", $scope, $y, $metrics, ['top_error_types' => $this->sample($types, 5)], 'INCOMING_IMPORT_ERRORS_SURGE'));
        }

        return CheckResult::single(Observation::healthy("入荷データ取込エラーは平常範囲（{$y}: {$yc} 件、中央値 {$median} 件）。", $scope, $y, $metrics));
    }
}
