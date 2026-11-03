<?php

namespace App\Monitoring\Support;

/**
 * 実行時の秘密値参照。`config:cache` 後は Laravel が .env を読まず `env()` が null になるため、
 * config/monitoring.php の `secret_values`（config 構築時に env() を解決した写し）を先に見る。
 * 値はここでは保存・出力しない。
 */
final class Env
{
    public static function get(?string $name): ?string
    {
        if ($name === null || $name === '') {
            return null;
        }
        $v = config('monitoring.secret_values')[$name] ?? null;
        if ($v === null || $v === '') {
            $v = env($name);
        }

        return $v === null || $v === '' ? null : (string) $v;
    }
}
