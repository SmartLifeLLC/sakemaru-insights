<?php

namespace App\Monitoring\Enums;

enum Severity: string
{
    case INFO = 'INFO';
    case WARNING = 'WARNING';
    case ERROR = 'ERROR';
    case CRITICAL = 'CRITICAL';

    /** Laravel/Monolog レベルからの固定変換（仕様書 6.1）。DEBUG は null（収集対象外）。不明値も null。 */
    public static function fromLogLevel(string $level): ?self
    {
        return match (strtoupper(trim($level))) {
            'DEBUG' => null,
            'INFO', 'NOTICE' => self::INFO,
            'WARNING' => self::WARNING,
            'ERROR' => self::ERROR,
            'CRITICAL', 'ALERT', 'EMERGENCY' => self::CRITICAL,
            default => null,
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::INFO => 10,
            self::WARNING => 20,
            self::ERROR => 30,
            self::CRITICAL => 40,
        };
    }

    public function isErrorOrAbove(): bool
    {
        return $this->rank() >= self::ERROR->rank();
    }

    public function label(): string
    {
        return match ($this) {
            self::INFO => '情報',
            self::WARNING => 'ワーニング',
            self::ERROR => 'エラー',
            self::CRITICAL => '重大',
        };
    }

    public function retentionClass(): string
    {
        return match ($this) {
            self::INFO => 'INFO',
            self::WARNING => 'WARNING',
            self::ERROR, self::CRITICAL => 'ERROR',
        };
    }
}
