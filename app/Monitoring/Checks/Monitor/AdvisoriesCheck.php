<?php

namespace App\Monitoring\Checks\Monitor;

use App\Models\Monitoring\Advisory;
use App\Monitoring\Advisories\AdvisoryService;
use App\Monitoring\Checks\AbstractCheck;
use App\Monitoring\Contracts\CheckDefinition;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\CheckResult;
use App\Monitoring\Services\Observation;

/**
 * monitor.advisories: 未確認の脆弱性情報のうち「重大」「悪用確認（KEV）」「導入版が影響範囲」があれば WARNING（確認すると復旧）。
 * 取得が止まっている（最終成功が stale_hours より古い）場合も WARNING。業務 DB には触れない。
 */
class AdvisoriesCheck extends AbstractCheck
{
    public function definition(): CheckDefinition
    {
        return new CheckDefinition('monitor.advisories', 'INSIGHTS', '脆弱性情報', '重大・悪用確認・導入版が影響する脆弱性情報が未確認のまま残っていないか。情報源からの取得が止まっていないか', 1, 3600, 1800, 'RB-23', false, false, 1, true, ['stale_hours' => 30], ['stale_hours']);
    }

    public function evaluate(CheckContext $ctx): CheckResult
    {
        if (! config('monitoring.advisories.enabled')) {
            return new CheckResult([Observation::notDue('脆弱性情報の収集は無効です。', ['queue' => 'advisories'])]);
        }
        $svc = app(AdvisoryService::class);
        $s = $svc->summary($ctx->nowUtc);
        $obs = [];
        if ($s['urgent'] > 0) {
            $sample = Advisory::query()->where('status', 'NEW')->where(fn ($q) => $q->where('severity', 'CRITICAL')->orWhere('kev', true)->orWhere('installed_affected', true))
                ->orderByDesc('kev')->orderByDesc('installed_affected')->orderByDesc('cvss')->limit(5)->get(['source', 'external_id', 'cve_id', 'title', 'severity', 'kev', 'installed_affected'])->toArray();
            $parts = array_filter([$s['critical'] ? '重大 '.$s['critical'] : null, $s['kev'] ? '悪用確認 '.$s['kev'] : null, $s['installed'] ? '導入版が影響 '.$s['installed'] : null]);
            $obs[] = Observation::warning('未確認の脆弱性報告が '.$s['urgent'].' 件あります（'.implode('・', $parts).'）。脆弱性情報の画面で確認してください。', ['queue' => 'advisories'], null,
                ['new' => $s['new'], 'urgent' => $s['urgent'], 'critical' => $s['critical'], 'kev' => $s['kev'], 'installed_affected' => $s['installed']], ['samples' => $sample], 'ADVISORY_UNREVIEWED');
        } else {
            $obs[] = Observation::healthy('対応を要する未確認の脆弱性情報はありません（新規 '.$s['new'].' 件）。', ['queue' => 'advisories'], null, ['new' => $s['new']]);
        }
        $staleHours = (int) $ctx->config('stale_hours', 30);
        if ($s['never']) {
            $obs[] = Observation::unknown('脆弱性情報はまだ一度も取得されていません（monitor:fetch-advisories / sakemaru-monitor-advisories timer）。', ['queue' => 'advisories_fetch'], null, [], 'ADVISORY_FETCH_NEVER');
        } elseif ($s['last_fetch_at']->lt($ctx->nowUtc->subHours($staleHours))) {
            $obs[] = Observation::warning('脆弱性情報の最終取得から '.(int) $s['last_fetch_at']->diffInHours($ctx->nowUtc).' 時間経過しています（取得失敗: '.(implode(', ', $s['failed_sources']) ?: 'なし').'）。', ['queue' => 'advisories_fetch'], null, ['failed_sources' => count($s['failed_sources'])], [], 'ADVISORY_FETCH_STALE');
        } else {
            $obs[] = Observation::healthy('脆弱性情報の取得は '.$s['last_fetch_at']->setTimezone('Asia/Tokyo')->format('m/d H:i').' JST に成功しています（'.$s['sources_ok'].' / '.$s['sources_total'].' 情報源）。', ['queue' => 'advisories_fetch']);
        }

        return new CheckResult($obs);
    }
}
