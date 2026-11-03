<?php

namespace App\Monitoring\Support;

final class PublicId
{
    public static function incident(int $id): string
    {
        return 'MON-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    public static function batch(int $id): string
    {
        return 'BAT-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    public static function parseIncident(string $publicId): ?int
    {
        if (preg_match('/^MON-(\d{1,12})$/i', trim($publicId), $m)) {
            return (int) $m[1];
        }

        return null;
    }
}
