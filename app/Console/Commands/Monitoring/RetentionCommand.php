<?php

namespace App\Console\Commands\Monitoring;

use App\Monitoring\Services\RetentionService;
use Illuminate\Console\Command;

class RetentionCommand extends Command
{
    protected $signature = 'monitor:retention';

    protected $description = '運用監視: 期限到来した監視データを小分け削除する（15分間隔・ジッター付き）';

    public function handle(RetentionService $retention): int
    {
        usleep(random_int(0, 5_000_000));
        $deleted = $retention->run();
        foreach ($deleted as $k => $n) {
            if ($n > 0) {
                $this->line("{$k}: {$n}");
            }
        }
        $this->line('total: '.array_sum($deleted));

        return self::SUCCESS;
    }
}
