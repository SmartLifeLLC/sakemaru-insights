<?php

namespace App\Monitoring\Enums;

enum NotificationStatus: string
{
    case PENDING = 'PENDING';
    case SENDING = 'SENDING';
    case RETRY_WAIT = 'RETRY_WAIT';
    case DELIVERED = 'DELIVERED';
    case BLOCKED = 'BLOCKED';
    case CANCELLED = 'CANCELLED';
    case UNANSWERED = 'UNANSWERED'; // 電話: 名簿を最大巡回しても確認が無かった（配送不可ではない）

    public function label(): string
    {
        return match ($this) {
            self::PENDING => '送信待ち',
            self::SENDING => '送信中',
            self::RETRY_WAIT => '再試行待ち',
            self::DELIVERED => '送信済',
            self::BLOCKED => '配送不可',
            self::CANCELLED => '取消',
            self::UNANSWERED => '未応答',
        };
    }
}
