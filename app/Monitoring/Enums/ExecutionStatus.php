<?php

namespace App\Monitoring\Enums;

enum ExecutionStatus: string
{
    case QUEUED = 'QUEUED';
    case RUNNING = 'RUNNING';
    case SUCCEEDED = 'SUCCEEDED';
    case FAILED = 'FAILED';
    case TIMED_OUT = 'TIMED_OUT';
    case CANCELLED = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::QUEUED => '待機',
            self::RUNNING => '実行中',
            self::SUCCEEDED => '完了',
            self::FAILED => '失敗',
            self::TIMED_OUT => 'タイムアウト',
            self::CANCELLED => '取消',
        };
    }
}
