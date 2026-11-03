<?php

namespace App\Monitoring\Enums;

/** チェック結果（仕様書 5.1）。未実行・タイムアウト・未設定を HEALTHY にしない。 */
enum Outcome: string
{
    case HEALTHY = 'HEALTHY';
    case WARNING = 'WARNING';
    case VIOLATION = 'VIOLATION';
    case UNKNOWN = 'UNKNOWN';
    case NOT_DUE = 'NOT_DUE';
    case DISABLED = 'DISABLED';

    public function label(): string
    {
        return match ($this) {
            self::HEALTHY => '正常',
            self::WARNING => 'ワーニング',
            self::VIOLATION => '違反',
            self::UNKNOWN => '未判定',
            self::NOT_DUE => '判定前',
            self::DISABLED => '無効',
        };
    }
}
