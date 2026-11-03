<?php

namespace App\Monitoring\Checks\Search;

use App\Monitoring\Checks\Common\QueueFailedCheck;

class QueueFailed extends QueueFailedCheck
{
    protected function app(): string
    {
        return 'SEARCH';
    }

    protected function table(): string
    {
        return 'srh_failed_jobs';
    }
}
