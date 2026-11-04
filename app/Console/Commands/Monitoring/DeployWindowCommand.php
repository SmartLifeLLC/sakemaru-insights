<?php

namespace App\Console\Commands\Monitoring;

use App\Monitoring\Services\DeployWindow;
use App\Monitoring\Services\SiteRegistry;
use Illuminate\Console\Command;

/**
 * デプロイ窓の開始・終了（deploy.sh から呼ぶ）。窓の間は自己監視の「完了していない」「heartbeat 停止」を障害にしない。
 * 予定時間で自動的に閉じるので、終了の記録が漏れても監視は止まらない。
 */
class DeployWindowCommand extends Command
{
    protected $signature = 'monitor:deploy-window
        {action : start / end / status}
        {--minutes=30 : 予定時間（5〜240 分）。超えたら自動で閉じる}
        {--reason=デプロイ : 理由（お知らせ・監査に表示）}
        {--node= : ノード名（既定は MONITOR_NODE_CODE）}';

    protected $description = 'デプロイ窓を記録し、その間の監視停止を障害にしない';

    public function handle(DeployWindow $windows, SiteRegistry $sites): int
    {
        $node = (string) ($this->option('node') ?: $sites->nodeCode());
        $who = (string) (getenv('SUDO_USER') ?: getenv('USER') ?: '');
        $actor = ['id' => null, 'name' => 'deploy@'.$node.($who !== '' ? " ({$who})" : '')];
        switch ($this->argument('action')) {
            case 'start':
                $w = $windows->start($node, (int) $this->option('minutes'), (string) $this->option('reason'), $actor);
                $this->line('deploy window start: '.$node.' until '.$w['until']->setTimezone('Asia/Tokyo')->format('H:i').' JST');

                return self::SUCCESS;
            case 'end':
                $this->line($windows->end($node, $actor) ? 'deploy window end: '.$node : 'deploy window: '.$node.' は開いていません');

                return self::SUCCESS;
            case 'status':
                $active = $windows->active();
                $this->line($active ? implode("\n", array_map(fn ($w) => $w['node'].' '.$w['started_at']->setTimezone('Asia/Tokyo')->format('H:i').'〜'.$w['until']->setTimezone('Asia/Tokyo')->format('H:i').' JST '.$w['reason'], $active)) : '開いているデプロイ窓はありません');

                return self::SUCCESS;
        }
        $this->error('action は start / end / status');

        return self::FAILURE;
    }
}
