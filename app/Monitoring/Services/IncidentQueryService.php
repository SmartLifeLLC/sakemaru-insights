<?php

namespace App\Monitoring\Services;

use App\Models\Monitoring\Incident;
use App\Monitoring\Enums\WorkflowStatus;
use App\Monitoring\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/** エラー管理一覧のキーセット検索（仕様書 11.3）。 */
class IncidentQueryService
{
    public const COLUMNS = ['id', 'public_id', 'site_id', 'check_key', 'error_code', 'severity', 'title', 'business_date', 'observation_state', 'workflow_status', 'generation', 'first_seen_at', 'last_seen_at', 'occurrence_count', 'assigned_name', 'acknowledged_at', 'next_reminder_at', 'snoozed_until', 'last_delivery_status', 'last_delivered_at', 'recovered_at', 'node_code', 'scope_json', 'runbook_key'];

    /**
     * @param  array<string,mixed>  $filters  site_ids[], statuses[] (workflow), severities[], tab (unacknowledged|critical|active|recovered|resolved|all), check_key, search
     */
    public function page(array $filters, ?string $cursor, int $pageSize, ?CarbonImmutable $now = null): KeysetPage
    {
        $now ??= CarbonImmutable::now('UTC');
        $filters = $this->normalize($filters);
        $hash = CanonicalJson::hash($filters);
        $fetch = function (int $anchor, ?int $afterId, ?int $beforeId, int $limit) use ($filters) {
            $q = $this->apply(Incident::query()->select(self::COLUMNS), $filters)->where('id', '<=', $anchor);
            if ($afterId !== null) {
                return $q->where('id', '>', $afterId)->orderBy('id')->limit($limit)->get()->all();
            }
            if ($beforeId !== null) {
                $q->where('id', '<', $beforeId);
            }

            return $q->orderByDesc('id')->limit($limit)->get()->all();
        };
        $maxId = fn () => (int) (Incident::query()->max('id') ?? 0);

        return KeysetPage::build($fetch, $maxId, $cursor, $hash, 'incidents', $pageSize, (int) config('monitoring.ui.cursor_ttl_seconds', 1800), $now->getTimestamp());
    }

    /** @return array<string,mixed> */
    public function normalize(array $f): array
    {
        return [
            'site_ids' => array_values(array_map('intval', array_filter((array) ($f['site_ids'] ?? [])))),
            'statuses' => array_values(array_filter(array_map('strtoupper', (array) ($f['statuses'] ?? [])))),
            'severities' => array_values(array_filter(array_map('strtoupper', (array) ($f['severities'] ?? [])))),
            'tab' => (string) ($f['tab'] ?? 'active'),
            'check_key' => isset($f['check_key']) && $f['check_key'] !== '' ? (string) $f['check_key'] : null,
            'search' => isset($f['search']) && trim((string) $f['search']) !== '' ? trim((string) $f['search']) : null,
        ];
    }

    public function apply(Builder $q, array $f): Builder
    {
        if ($f['site_ids']) {
            $q->whereIn('site_id', $f['site_ids']);
        }
        switch ($f['tab']) {
            case 'unacknowledged':
                $q->where('workflow_status', WorkflowStatus::OPEN->value);
                break;
            case 'critical':
                $q->whereIn('workflow_status', WorkflowStatus::unresolvedValues())->where('severity', 'CRITICAL');
                break;
            case 'recovered':
                $q->whereIn('workflow_status', WorkflowStatus::unresolvedValues())->where('observation_state', 'RECOVERED');
                break;
            case 'resolved':
                $q->whereIn('workflow_status', [WorkflowStatus::RESOLVED->value, WorkflowStatus::CLOSED->value]);
                break;
            case 'all':
                break;
            default: // active
                $q->whereIn('workflow_status', WorkflowStatus::unresolvedValues());
        }
        if ($f['statuses']) {
            $q->whereIn('workflow_status', $f['statuses']);
        }
        if ($f['severities']) {
            $q->whereIn('severity', $f['severities']);
        }
        if ($f['check_key']) {
            $q->where('check_key', $f['check_key']);
        }
        if ($f['search']) {
            $s = $f['search'];
            if (preg_match('/^MON-\d+$/i', $s)) {
                $q->where('public_id', strtoupper($s));
            } elseif (preg_match('/^[0-9a-f]{64}$/i', $s)) {
                $q->where('fingerprint', strtolower($s));
            } else {
                // 登録コードの前方一致のみ（自由文全文検索は初版対象外）
                $q->where(fn ($w) => $w->where('check_key', 'like', $s.'%')->orWhere('error_code', 'like', $s.'%'));
            }
        }

        return $q;
    }

    /**
     * 概要用: 未解決件数のサイト別集約（未解決は小さい集合なので索引 (site_id, workflow_status) で数える）。
     *
     * @return array<int,array<string,int>>
     */
    public function unresolvedCountsBySite(): array
    {
        $rows = Incident::query()
            ->selectRaw('site_id, workflow_status, observation_state, severity, COUNT(*) as c')
            ->whereIn('workflow_status', WorkflowStatus::unresolvedValues())
            ->groupBy('site_id', 'workflow_status', 'observation_state', 'severity')
            ->get();
        $out = [];
        foreach ($rows as $r) {
            $sid = (int) $r->site_id;
            $out[$sid] ??= ['open' => 0, 'acknowledged' => 0, 'in_progress' => 0, 'recovered_waiting' => 0, 'critical' => 0, 'total' => 0];
            $c = (int) $r->c;
            $out[$sid]['total'] += $c;
            $out[$sid][strtolower($r->workflow_status->value)] += $c;
            if ($r->observation_state->value === 'RECOVERED') {
                $out[$sid]['recovered_waiting'] += $c;
            }
            if ($r->severity->value === 'CRITICAL') {
                $out[$sid]['critical'] += $c;
            }
        }

        return $out;
    }
}
