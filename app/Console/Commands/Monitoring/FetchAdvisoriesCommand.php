<?php

namespace App\Console\Commands\Monitoring;

use App\Models\Monitoring\Advisory;
use App\Monitoring\Advisories\AdvisoryFetcher;
use App\Monitoring\Services\SiteRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * 外部情報源から脆弱性情報を取得して ins_monitor_advisories に保存する（owner ノードの timer から 6 時間ごと）。
 * 業務 DB には触れない。秘密値（API キー）は出力しない。
 */
class FetchAdvisoriesCommand extends Command
{
    protected $signature = 'monitor:fetch-advisories
        {--source=* : 情報源キー（nvd,github,ubuntu,kev,aws,jvn）で絞る}
        {--since= : この日時（UTC）以降を取り直す。例 2026-10-01}
        {--ignore-owner : owner 判定を無視（ローカル検証用）}';

    protected $description = '公開情報源から脆弱性情報を収集する（NVD / GitHub Advisory / Ubuntu USN / CISA KEV / AWS / JVN）';

    public function handle(AdvisoryFetcher $fetcher, SiteRegistry $sites): int
    {
        if (! config('monitoring.enabled') || ! config('monitoring.advisories.enabled')) {
            $this->warn('MONITOR_ENABLED または MONITOR_ADVISORIES_ENABLED が false のため実行しません。');

            return self::SUCCESS;
        }
        $owner = (string) config('monitoring.deployment.check_owner_node');
        if (! $this->option('ignore-owner') && $owner !== $sites->nodeCode()) {
            $this->warn("このノード({$sites->nodeCode()})は owner({$owner}) ではありません。取得は owner ノードだけが行います。");

            return self::SUCCESS;
        }
        $only = array_values(array_filter((array) $this->option('source')));
        $since = $this->option('since') ? CarbonImmutable::parse((string) $this->option('since'), 'UTC') : null;
        $res = $fetcher->run(CarbonImmutable::now('UTC'), $only ?: null, $since);
        $failed = 0;
        foreach ($res as $key => $r) {
            $label = Advisory::SOURCES[$key] ?? $key;
            if ($r['status'] === 'OK') {
                $this->line(sprintf('%-16s OK   取得 %3d 件 / 新規 %3d 件', $label, $r['fetched'], $r['new']));
            } else {
                $failed++;
                $this->error(sprintf('%-16s FAIL %s', $label, $r['error']));
            }
        }
        $new = Advisory::query()->where('status', 'NEW')->count();
        $this->line("未確認の脆弱性情報: {$new} 件（重大 ".Advisory::query()->where('status', 'NEW')->where('severity', 'CRITICAL')->count().' / 悪用確認 '.Advisory::query()->where('status', 'NEW')->where('kev', true)->count().'）');

        return $failed === count($res) && $res !== [] ? self::FAILURE : self::SUCCESS;
    }
}
