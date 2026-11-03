<?php

namespace App\Monitoring\Enums;

/** エラー条件が現在も存在するか（人の対応状態とは別）。 */
enum ObservationState: string
{
    case ACTIVE = 'ACTIVE';
    case RECOVERED = 'RECOVERED';
    case UNKNOWN = 'UNKNOWN';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => '継続中',
            self::RECOVERED => '復旧',
            self::UNKNOWN => '確認不能',
        };
    }
}
