<?php

namespace App\Console\Commands\Monitoring;

use App\Monitoring\Services\IncidentService;
use App\Monitoring\Services\NotificationDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class DispatchNotificationsCommand extends Command
{
    protected $signature = 'monitor:dispatch-notifications {--loop=0 : 秒数を指定すると、その秒数までループ実行（systemd から 50 を推奨）}';

    protected $description = '運用監視: 再通知の作成と Slack 通知待ちの配送（15〜60秒間隔）';

    public function handle(IncidentService $incidents, NotificationDispatcher $dispatcher): int
    {
        $until = microtime(true) + (int) $this->option('loop');
        do {
            $now = CarbonImmutable::now('UTC');
            $reminders = $incidents->processReminders($now);
            $stats = $dispatcher->dispatch($now);
            $this->line(sprintf('[%s] reminders=%d sent=%d retried=%d blocked=%d cancelled=%d%s', $now->setTimezone('Asia/Tokyo')->format('H:i:s'), $reminders, $stats['sent'], $stats['retried'], $stats['blocked'], $stats['cancelled'], $stats['skipped'] === -1 ? ' (通知は全面停止中)' : ''));
            if (microtime(true) < $until) {
                sleep(10);
            }
        } while (microtime(true) < $until);

        return self::SUCCESS;
    }
}
