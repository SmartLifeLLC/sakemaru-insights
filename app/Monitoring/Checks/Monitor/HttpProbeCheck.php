<?php

namespace App\Monitoring\Checks\Monitor;

use App\Monitoring\Checks\AbstractCheck;
use App\Monitoring\Contracts\CheckDefinition;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\CheckResult;
use App\Monitoring\Services\Observation;
use App\Monitoring\Services\ProbeRunner;
use App\Monitoring\Services\SiteRegistry;

/**
 * http.probe: 各サービスのヘルスチェック URL（/up）の応答。60 秒間隔・タイムアウト 5 秒。
 * 1 回の失敗は WARNING、連続 3 回で ERROR（v2 V-050）。観測は対象サービスに帰属させる（Slack 見出しが「[蔵 WMS]」になる）。
 */
class HttpProbeCheck extends AbstractCheck
{
    use ActiveScopes;

    public function __construct(private readonly ProbeRunner $runner, private readonly SiteRegistry $sites) {}

    public function definition(): CheckDefinition
    {
        return new CheckDefinition('http.probe', 'INSIGHTS', 'HTTP 死活・応答時間', '各サービスのヘルスチェック URL が応答しているか', 1,
            (int) config('monitoring.probe.interval_seconds', 60), 120, 'RB-20', false, false, 2, true,
            ['slow_warning_ms' => (int) config('monitoring.probe.slow_warning_ms', 2000), 'error_after_failures' => (int) config('monitoring.probe.error_after_failures', 3)],
            ['slow_warning_ms', 'error_after_failures']);
    }

    public function evaluate(CheckContext $ctx): CheckResult
    {
        $results = $this->runner->run($ctx->nowUtc);
        if (! $results) {
            return new CheckResult([Observation::healthy('HTTP プローブの対象はありません（未登録または機能が無効）。', ['probe' => 'none'])]);
        }
        $slow = (int) $ctx->config('slow_warning_ms', 2000);
        $errorAfter = max(1, (int) $ctx->config('error_after_failures', 3));
        $active = $this->activeScopes('http.probe');
        $obs = [];
        $okCount = 0;
        foreach ($results as $r) {
            $t = $r['target'];
            $res = $r['result'];
            $site = $t->site_id ? $this->sites->siteById((int) $t->site_id) : null;
            $code = $site?->application_code ?? 'INSIGHTS';
            $label = $this->sites->label($code).' '.$t->display_name;
            $scope = ['probe_target' => (int) $t->id];
            $metrics = ['http_status' => $res->http_status, 'duration_ms' => $res->duration_ms, 'consecutive_failures' => $r['consecutive_failures']];
            $evidence = ['url' => (string) ($t->params_json['url'] ?? ''), 'error_code' => $res->error_code];
            if (! $res->ok) {
                $what = $res->error_code === 'HTTP_STATUS' || $res->error_code === 'HTTP_REDIRECT' ? 'HTTP '.$res->http_status : $res->error_code;
                $o = $r['consecutive_failures'] >= $errorAfter
                    ? Observation::violation("{$label} が {$r['consecutive_failures']} 回連続で応答していません（{$what}）。", $scope, null, $metrics, $evidence, 'HTTP_PROBE_FAILED')
                    : Observation::warning("{$label} の応答に失敗しました（{$what}、連続 {$r['consecutive_failures']} 回）。", $scope, null, $metrics, $evidence, 'HTTP_PROBE_FAILED');
                $obs[] = $o->forSite($code);
            } elseif ($res->duration_ms !== null && $res->duration_ms >= $slow) {
                $obs[] = Observation::warning("{$label} の応答が遅くなっています（{$res->duration_ms}ms）。", ['probe_target' => (int) $t->id, 'state' => 'slow'], null, $metrics, $evidence, 'HTTP_PROBE_SLOW')->forSite($code);
                if (isset($active[$this->scopeKey($scope)])) {
                    $obs[] = Observation::healthy("{$label} は応答しています（{$res->duration_ms}ms）。", $scope, null, $metrics)->forSite($code);
                }
            } else {
                $okCount++;
                if (isset($active[$this->scopeKey($scope)])) {
                    $obs[] = Observation::healthy("{$label} は応答しています（{$res->duration_ms}ms）。", $scope, null, $metrics)->forSite($code);
                }
            }
        }
        if ($okCount > 0) {
            $obs[] = Observation::healthy("HTTP プローブ {$okCount}/".count($results).' 件が正常に応答しました。', ['probe' => 'summary'], null, ['ok' => $okCount, 'total' => count($results)]);
        }

        return new CheckResult($obs);
    }
}
