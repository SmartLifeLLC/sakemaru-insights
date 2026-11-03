<?php

namespace App\Monitoring\Enums;

enum SourceKind: string
{
    case CHECK = 'CHECK';
    case APPLICATION_LOG = 'APPLICATION_LOG';
    case MONITOR_SYSTEM = 'MONITOR_SYSTEM';

    public function label(): string
    {
        return match ($this) {
            self::CHECK => 'チェック',
            self::APPLICATION_LOG => 'アプリログ',
            self::MONITOR_SYSTEM => '監視システム',
        };
    }
}
