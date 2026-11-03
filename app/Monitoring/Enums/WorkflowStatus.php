<?php

namespace App\Monitoring\Enums;

enum WorkflowStatus: string
{
    case OPEN = 'OPEN';
    case ACKNOWLEDGED = 'ACKNOWLEDGED';
    case IN_PROGRESS = 'IN_PROGRESS';
    case RESOLVED = 'RESOLVED';
    case CLOSED = 'CLOSED';

    public function label(): string
    {
        return match ($this) {
            self::OPEN => '未確認',
            self::ACKNOWLEDGED => '確認済',
            self::IN_PROGRESS => '対応中',
            self::RESOLVED => '解決',
            self::CLOSED => '終了',
        };
    }

    public function isOpenish(): bool
    {
        return in_array($this, [self::OPEN, self::ACKNOWLEDGED, self::IN_PROGRESS], true);
    }

    /** @return string[] */
    public static function unresolvedValues(): array
    {
        return [self::OPEN->value, self::ACKNOWLEDGED->value, self::IN_PROGRESS->value];
    }
}
