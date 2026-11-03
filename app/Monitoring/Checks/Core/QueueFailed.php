<?php

namespace App\Monitoring\Checks\Core;

use App\Monitoring\Checks\Common\QueueFailedCheck;

class QueueFailed extends QueueFailedCheck
{
    protected function app(): string
    {
        return 'CORE';
    }

    protected function table(): string
    {
        return 'failed_jobs';
    }
}
