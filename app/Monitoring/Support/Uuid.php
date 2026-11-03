<?php

namespace App\Monitoring\Support;

final class Uuid
{
    public const NS_MONITOR = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';

    /** 名前ベースの決定的 UUID（v5）。再送でも同じ値になる event_id の生成に使う。 */
    public static function v5(string $namespace, string $name): string
    {
        return \Ramsey\Uuid\Uuid::uuid5($namespace, $name)->toString();
    }
}
