<?php

namespace App\Monitoring\Enums;

enum TargetKind: string
{
    case APPLICATION_LOG = 'APPLICATION_LOG';
    case HTTP_PROBE = 'HTTP_PROBE';
    case PROCESS = 'PROCESS';

    public function label(): string
    {
        return match ($this) {
            self::APPLICATION_LOG => 'アプリログ',
            self::HTTP_PROBE => 'HTTP 死活',
            self::PROCESS => 'プロセス',
        };
    }
}
