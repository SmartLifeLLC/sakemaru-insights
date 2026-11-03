<?php

namespace App\Monitoring\Services;

use App\Models\Monitoring\Check as CheckModel;
use App\Models\Monitoring\Incident;
use App\Models\Monitoring\RecheckRequest;
use App\Monitoring\Exceptions\InvalidTransition;
use App\Monitoring\Support\CanonicalJson;
use App\Monitoring\Support\Fingerprint;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * 読取り専用の「再確認予約」（仕様書 11.5）。
 * HTTP リクエスト内では SELECT を実行せず、実行要求だけを保存する。対象単位で重複予約をまとめる。
 */
class RecheckService
{
    public function __construct(private readonly CheckRegistry $registry, private readonly SiteRegistry $sites, private readonly AuditService $audit) {}

    /** @param array{id:int|null,name:string} $actor */
    public function enqueue(CheckModel $check, array $scope, array $actor, ?Incident $incident = null): RecheckRequest
    {
        $site = $this->sites->siteById((int) $check->site_id);
        $def = $site ? $this->registry->definition($site->application_code, $check->check_key) : null;
        if (! $def || ! $def->implemented || ! $check->enabled) {
            throw new InvalidTransition('このチェックは再確認できません（未実装または無効）。');
        }
        $scope = Fingerprint::normalizeScope($scope);
        $hash = CanonicalJson::hash($scope);
        $existing = RecheckRequest::query()->where('check_id', $check->id)->where('scope_hash', $hash)->whereIn('status', ['QUEUED', 'RUNNING', 'BUDGET_WAIT'])->first();
        if ($existing) {
            return $existing;
        }
        $now = CarbonImmutable::now('UTC');
        $req = RecheckRequest::query()->create([
            'request_id' => (string) Str::uuid(),
            'check_id' => $check->id,
            'incident_id' => $incident?->id,
            'scope_json' => $scope,
            'scope_hash' => $hash,
            'status' => 'QUEUED',
            'requested_user_id' => $actor['id'],
            'requested_name' => $actor['name'],
            'requested_at' => $now,
            'expires_at' => $now->addDays((int) config('monitoring.retention.info_days', 30)),
        ]);
        $this->audit->record($actor, 'recheck.enqueue', 'check', (string) $check->id, null, ['scope' => $scope, 'request_id' => $req->request_id], $incident?->public_id);

        return $req;
    }
}
