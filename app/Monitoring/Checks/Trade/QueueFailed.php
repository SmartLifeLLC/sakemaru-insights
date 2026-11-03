<?php

namespace App\Monitoring\Checks\Trade;

use App\Monitoring\Checks\Common\QueueFailedCheck;

class QueueFailed extends QueueFailedCheck
{
    protected function app(): string
    {
        return 'TRADE';
    }

    protected function table(): string
    {
        return 'ret_failed_jobs';
    }
}
