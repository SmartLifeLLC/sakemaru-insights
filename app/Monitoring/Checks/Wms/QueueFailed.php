<?php

namespace App\Monitoring\Checks\Wms;

use App\Monitoring\Checks\Common\QueueFailedCheck;

class QueueFailed extends QueueFailedCheck
{
    protected function app(): string
    {
        return 'WMS';
    }

    protected function table(): string
    {
        return 'wms_failed_jobs';
    }
}
