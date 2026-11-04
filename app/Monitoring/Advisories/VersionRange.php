<?php

namespace App\Monitoring\Advisories;

/**
 * GitHub Advisory の影響範囲（例 ">= 9.0.0, < 9.52.17" / "< 10.48.23" / "= 1.2.3"）に版が入るかの簡易判定。
 * composer/semver は依存に無いので version_compare で行う。判定できない式は null（不明）。
 */
final class VersionRange
{
    public static function contains(string $range, string $version): ?bool
    {
        $version = ltrim(trim($version), 'v');
        if ($version === '' || ! preg_match('/^\d+(\.\d+)*/', $version)) {
            return null;
        }
        $parts = array_filter(array_map('trim', explode(',', $range)));
        if ($parts === []) {
            return null;
        }
        foreach ($parts as $p) {
            if (! preg_match('/^(>=|<=|>|<|=)\s*v?(\d+(?:\.\d+)*(?:[-+][0-9A-Za-z.-]+)?)$/', $p, $m)) {
                return null;
            }
            $cmp = version_compare($version, $m[2]);
            $ok = match ($m[1]) {
                '>=' => $cmp >= 0,
                '<=' => $cmp <= 0,
                '>' => $cmp > 0,
                '<' => $cmp < 0,
                '=' => $cmp === 0,
            };
            if (! $ok) {
                return false;
            }
        }

        return true;
    }
}
