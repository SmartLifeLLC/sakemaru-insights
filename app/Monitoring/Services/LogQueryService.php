<?php

namespace App\Monitoring\Services;

use App\Models\Monitoring\LogEntry;
use App\Monitoring\Support\CanonicalJson;
use App\Monitoring\Support\PublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * 監視ログ一覧のキーセット検索（仕様書 11.2 / 12）。
 * 保存済みの監視データだけを参照し、業務DBへは一切アクセスしない。
 */
class LogQueryService
{
    public const COLUMNS = ['id', 'received_at', 'occurred_at', 'site_id', 'node_code', 'severity', 'source_kind', 'check_key', 'error_code', 'outcome', 'business_date', 'summary', 'incident_id', 'occurrence_delta', 'run_id'];

    /**
     * @param  array<string,mixed>  $filters  site_ids[], severities[], check_key, from(UTC), to(UTC), business_date, node_code, incident_public_id, source_kind
     */
    public function page(array $filters, ?string $cursor, int $pageSize, ?CarbonImmutable $now = null): KeysetPage
    {
        $now ??= CarbonImmutable::now('UTC');
        $filters = $this->normalize($filters, $now);
        $hash = CanonicalJson::hash($filters);
        $fetch = function (int $anchor, ?int $afterId, ?int $beforeId, int $limit) use ($filters) {
            $q = $this->apply(LogEntry::query()->select(self::COLUMNS), $filters)->where('id', '<=', $anchor);
            if ($afterId !== null) {
                return $q->where('id', '>', $afterId)->orderBy('id')->limit($limit)->get()->all();
            }
            if ($beforeId !== null) {
                $q->where('id', '<', $beforeId);
            }

            return $q->orderByDesc('id')->limit($limit)->get()->all();
        };
        $maxId = fn () => (int) (LogEntry::query()->max('id') ?? 0);

        return KeysetPage::build($fetch, $maxId, $cursor, $hash, 'logs', $pageSize, (int) config('monitoring.ui.cursor_ttl_seconds', 1800), $now->getTimestamp());
    }

    /** @return array<string,mixed> */
    public function normalize(array $f, CarbonImmutable $now): array
    {
        $ui = config('monitoring.ui');
        $out = [
            'site_ids' => array_values(array_map('intval', array_filter((array) ($f['site_ids'] ?? [])))),
            'severities' => array_values(array_filter(array_map('strtoupper', (array) ($f['severities'] ?? [])))),
            'check_key' => isset($f['check_key']) && $f['check_key'] !== '' ? (string) $f['check_key'] : null,
            'node_code' => isset($f['node_code']) && $f['node_code'] !== '' ? (string) $f['node_code'] : null,
            'business_date' => isset($f['business_date']) && $f['business_date'] !== '' ? CarbonImmutable::parse((string) $f['business_date'])->toDateString() : null,
            'source_kind' => isset($f['source_kind']) && $f['source_kind'] !== '' ? (string) $f['source_kind'] : null,
            'incident_id' => null,
        ];
        if (! empty($f['incident_public_id'])) {
            $id = PublicId::parseIncident((string) $f['incident_public_id']);
            if ($id === null) {
                throw new InvalidArgumentException('エラー番号は MON-000123 の形式で指定してください。');
            }
            $out['incident_id'] = $id;
        }
        $to = ! empty($f['to']) ? CarbonImmutable::parse((string) $f['to'])->utc() : $now->addSecond();
        $from = ! empty($f['from']) ? CarbonImmutable::parse((string) $f['from'])->utc() : $to->subHours((int) $ui['default_log_window_hours']);
        if ($from->greaterThan($to)) {
            throw new InvalidArgumentException('期間の開始が終了より後になっています。');
        }
        $maxDays = (int) $ui['max_log_window_days'];
        $onlyWarning = $out['severities'] && ! array_diff($out['severities'], ['WARNING']);
        $onlyError = $out['severities'] && ! array_diff($out['severities'], ['ERROR', 'CRITICAL']);
        if ($onlyWarning) {
            $maxDays = (int) $ui['max_warning_window_days'];
        } elseif ($onlyError) {
            $maxDays = (int) $ui['max_error_window_days'];
        }
        if ($from->diffInDays($to) > $maxDays) {
            throw new InvalidArgumentException("検索期間は最大{$maxDays}日です（期間を黙って全期間へ広げません）。");
        }
        $out['from'] = $from->format('Y-m-d H:i:s');
        $out['to'] = $to->format('Y-m-d H:i:s');

        return $out;
    }

    public function apply(Builder $q, array $f): Builder
    {
        if ($f['site_ids']) {
            $q->whereIn('site_id', $f['site_ids']);
        }
        if ($f['severities']) {
            $q->whereIn('severity', $f['severities']);
        }
        if ($f['check_key']) {
            $q->where('check_key', $f['check_key']);
        }
        if ($f['node_code']) {
            $q->where('node_code', $f['node_code']);
        }
        if ($f['business_date']) {
            $q->where('business_date', $f['business_date']);
        }
        if ($f['source_kind']) {
            $q->where('source_kind', $f['source_kind']);
        }
        if ($f['incident_id']) {
            $q->where('incident_id', $f['incident_id']);
        }
        $q->where('received_at', '>=', $f['from'])->where('received_at', '<', $f['to']);

        return $q;
    }
}
