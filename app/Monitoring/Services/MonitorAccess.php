<?php

namespace App\Monitoring\Services;

use App\Monitoring\Exceptions\AccessDenied;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * 権限（仕様書 第17章）。既存の権限体系が未整備のため config で対応付ける初版実装。
 * パネルへ入れること（canAccessPanel）を編集権限として流用しない。
 */
class MonitorAccess
{
    public const VIEW = 'monitor.view';

    public const RESPOND = 'monitor.respond';

    public const MANAGE = 'monitor.manage';

    public const DELIVERY_ADMIN = 'monitor.delivery_admin';

    public function can(?Authenticatable $user, string $permission, ?string $siteCode = null): bool
    {
        if (! $user) {
            return false;
        }
        $allowed = (array) (((array) config('monitoring.permissions', []))[$permission] ?? []); // 未定義の権限は拒否
        if (! $allowed) {
            return false;
        }
        $ids = array_filter([
            isset($user->email) ? strtolower((string) $user->email) : null,
            isset($user->code) ? (string) $user->code : null,
            (string) $user->getAuthIdentifier(),
        ]);
        foreach ($allowed as $entry) {
            $entry = trim((string) $entry);
            if ($entry === '') {
                continue;
            }
            [$who, $sites] = array_pad(explode('@sites:', $entry, 2), 2, null);
            $match = $who === '*' || in_array(strtolower($who), array_map('strtolower', $ids), true);
            if (! $match) {
                continue;
            }
            if ($siteCode && $sites) {
                return in_array(strtoupper($siteCode), array_map('strtoupper', explode('|', $sites)), true);
            }

            return true;
        }

        return false;
    }

    public function authorize(?Authenticatable $user, string $permission, ?string $siteCode = null): void
    {
        if (! $this->can($user, $permission, $siteCode)) {
            throw new AccessDenied("権限がありません: {$permission}".($siteCode ? " ({$siteCode})" : ''));
        }
    }

    /** @return array{id:int|null,name:string} */
    public function actor(?Authenticatable $user): array
    {
        return [
            'id' => $user ? (int) $user->getAuthIdentifier() : null,
            'name' => $user ? (string) ($user->name ?? $user->email ?? $user->getAuthIdentifier()) : 'system',
        ];
    }

    /** @return string[] 閲覧可能なサイトコード（'*' は全て） */
    public function viewableSites(?Authenticatable $user, array $allSiteCodes): array
    {
        return array_values(array_filter($allSiteCodes, fn ($c) => $this->can($user, self::VIEW, $c)));
    }
}
