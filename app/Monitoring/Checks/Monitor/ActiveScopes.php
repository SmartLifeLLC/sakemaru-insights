<?php

namespace App\Monitoring\Checks\Monitor;

use App\Models\Monitoring\Incident;
use App\Monitoring\Enums\ObservationState;
use App\Monitoring\Support\CanonicalJson;
use App\Monitoring\Support\Fingerprint;

/**
 * 高頻度チェック（毎分）の HEALTHY ログを減らすため、継続中インシデントがある対象だけ個別に HEALTHY を出す。
 * それ以外の正常はチェック全体で 1 行にまとめる。
 */
trait ActiveScopes
{
    /** @return array<string,true> 正規化 scope の JSON => true */
    protected function activeScopes(string $checkKey): array
    {
        $out = [];
        foreach (Incident::query()->where('check_key', $checkKey)->where('observation_state', ObservationState::ACTIVE->value)->limit(500)->pluck('scope_json') as $scope) {
            $out[$this->scopeKey(is_array($scope) ? $scope : (array) json_decode((string) $scope, true))] = true;
        }

        return $out;
    }

    /** @return array<int,array<string,mixed>> 未解決（ACTIVE）案件の scope の一覧 */
    protected function activeScopeList(string $checkKey): array
    {
        $out = [];
        foreach (Incident::query()->where('check_key', $checkKey)->where('observation_state', ObservationState::ACTIVE->value)->limit(500)->pluck('scope_json') as $scope) {
            $out[] = is_array($scope) ? $scope : (array) json_decode((string) $scope, true);
        }

        return $out;
    }

    protected function scopeKey(array $scope): string
    {
        return CanonicalJson::encode(Fingerprint::normalizeScope($scope));
    }
}
