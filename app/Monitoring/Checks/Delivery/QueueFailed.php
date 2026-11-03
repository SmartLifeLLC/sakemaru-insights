<?php

namespace App\Monitoring\Checks\Delivery;

use App\Monitoring\Checks\Common\QueueFailedCheck;

class QueueFailed extends QueueFailedCheck
{
    protected function app(): string
    {
        return 'DELIVERY';
    }

    protected function table(): string
    {
        return 'dlv_failed_jobs';
    }
}
