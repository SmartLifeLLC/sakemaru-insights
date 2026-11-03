<?php

namespace App\Monitoring\Enums;

enum NotificationKind: string
{
    case NEW = 'NEW';
    case RECURRENCE = 'RECURRENCE';
    case ESCALATION = 'ESCALATION';
    case REMINDER = 'REMINDER';
    case RECOVERY = 'RECOVERY';
    case SUPPRESSION_EXPIRED = 'SUPPRESSION_EXPIRED';
    case TEST = 'TEST';

    public function label(): string
    {
        return match ($this) {
            self::NEW => '新規',
            self::RECURRENCE => '再発',
            self::ESCALATION => '昇格',
            self::REMINDER => '継続',
            self::RECOVERY => '復旧',
            self::SUPPRESSION_EXPIRED => '抑止期限切れ',
            self::TEST => 'テスト',
        };
    }
}
