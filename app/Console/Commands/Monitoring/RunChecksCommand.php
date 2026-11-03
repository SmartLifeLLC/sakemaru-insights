<?php

namespace App\Console\Commands\Monitoring;

use App\Monitoring\Services\CheckRunner;
use App\Monitoring\Services\RunbookRegistry;
use Illuminate\Console\Command;

class RunChecksCommand extends Command
{
    protected $signature = 'monitor:run-checks
        {--only=* : APP:check_key で限定（例: TRADE:trade.coupon_daily）}
        {--force : 期限に関係なく実行}
        {--ignore-owner : owner 判定を無視（ローカル検証用）}';

    protected $description = '運用監視: 期限到来した監視チェックを実行する（systemd timer から1分間隔で起動）';

    public function handle(CheckRunner $runner, RunbookRegistry $runbooks): int
    {
        if (! config('monitoring.enabled')) {
            $this->warn('MONITOR_ENABLED=false のため実行しません。');

            return self::SUCCESS;
        }
        $runbooks->sync();
        $stats = $runner->run(null, $this->option('only') ?: null, (bool) $this->option('force'), (bool) $this->option('ignore-owner'));
        if ($stats['message']) {
            $this->warn($stats['message']);
        }
        $this->line(sprintf('executed=%d failed=%d skipped=%d rechecks=%d timed_out_runs=%d', $stats['executed'], $stats['failed'], $stats['skipped'], $stats['rechecks'], $stats['timed_out_runs']));

        return $stats['message'] && $stats['executed'] === 0 && str_contains($stats['message'], 'owner') ? self::FAILURE : self::SUCCESS;
    }
}
