<?php

namespace App\Monitoring\Enums;

/** サイト監視範囲。未導入サイトを緑表示しない。 */
enum Coverage: string
{
    case CONFIGURED = 'CONFIGURED';
    case NOT_CONFIGURED = 'NOT_CONFIGURED';
    case DISABLED = 'DISABLED';
    case STALE = 'STALE';

    public function label(): string
    {
        return match ($this) {
            self::CONFIGURED => '監視中',
            self::NOT_CONFIGURED => '未監視',
            self::DISABLED => '停止',
            self::STALE => '更新停止',
        };
    }
}
