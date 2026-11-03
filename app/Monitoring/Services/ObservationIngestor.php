<?php

namespace App\Monitoring\Services;

use App\Models\Monitoring\Collector;
use App\Models\Monitoring\LogEntry;
use App\Models\Monitoring\Site;
use App\Monitoring\Enums\Outcome;
use App\Monitoring\Enums\Severity;
use App\Monitoring\Enums\SourceKind;
use App\Monitoring\Support\CanonicalJson;
use App\Monitoring\Support\Fingerprint;
use App\Monitoring\Support\Redactor;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 収集イベントの取込（仕様書 第6章 / 4.1 ObservationIngestor）。
 * 入力を検証し、重複を除外し、ログ保存・エラー状態変更・通知待ち追加を短い取引で確定する。
 * 業務DB照合や Slack 送信はしない。確定したイベントだけ ACK を返す。
 */
class ObservationIngestor
{
    public function __construct(
        private readonly SiteRegistry $sites,
        private readonly CheckRegistry $checks,
        private readonly IncidentService $incidents,
        private readonly CounterService $counters,
    ) {}

    /**
     * @param  array<int,array<string,mixed>>  $events
     * @param  Collector|null  $collector  認証済み収集器（内部イベントは internalCollector）
     * @return array{accepted:string[],duplicate:string[],rejected:array<int,array{event_id:?string,reason:string}>,conflict:string[]}
     */
    public function ingest(array $events, Collector $collector): array
    {
        $result = ['accepted' => [], 'duplicate' => [], 'rejected' => [], 'conflict' => []];
        $maxBatch = (int) config('monitoring.ingest.max_batch_events', 100);
        if (count($events) > $maxBatch) {
            foreach (array_slice($events, $maxBatch) as $e) {
                $result['rejected'][] = ['event_id' => $e['event_id'] ?? null, 'reason' => 'batch_too_large'];
            }
            $events = array_slice($events, 0, $maxBatch);
        }
        $chunkSize = (int) config('monitoring.ingest.max_transaction_events', 20);
        foreach (array_chunk($events, max(1, $chunkSize)) as $chunk) {
            $validated = [];
            foreach ($chunk as $raw) {
                $v = $this->validate($raw, $collector);
                if (isset($v['error'])) {
                    $result['rejected'][] = ['event_id' => is_string($raw['event_id'] ?? null) ? $raw['event_id'] : null, 'reason' => $v['error']];
                } else {
                    $validated[] = $v;
                }
            }
            if (! $validated) {
                continue;
            }
            try {
                $chunkResult = DB::connection('monitor_store')->transaction(function () use ($validated, $collector) {
                    $r = ['accepted' => [], 'duplicate' => [], 'conflict' => []];
                    foreach ($validated as $ev) {
                        $status = $this->store($ev, $collector);
                        $r[$status][] = $ev['event_id'];
                    }

                    return $r;
                }, 2);
                $result['accepted'] = array_merge($result['accepted'], $chunkResult['accepted']);
                $result['duplicate'] = array_merge($result['duplicate'], $chunkResult['duplicate']);
                $result['conflict'] = array_merge($result['conflict'], $chunkResult['conflict']);
            } catch (\Throwable $e) {
                // 保存失敗は ACK しない（AC-17）。収集器は退避から再送する。
                foreach ($validated as $ev) {
                    $result['rejected'][] = ['event_id' => $ev['event_id'], 'reason' => 'store_failed'];
                }
                report($e);
            }
        }
        $this->counters->flush();

        return $result;
    }

    /** 内部（チェック実行器・自己監視）からの取込。例外は呼出し側へ伝播。 */
    public function ingestInternal(array $events, string $purpose = 'checker'): array
    {
        return $this->ingest($events, $this->sites->internalCollector($purpose));
    }

    /**
     * @return array<string,mixed> 正規化済みイベント、または ['error' => reason]
     */
    public function validate(array $raw, Collector $collector): array
    {
        $cfg = config('monitoring.ingest');
        if ((int) ($raw['schema_version'] ?? 0) !== (int) $cfg['schema_version']) {
            return ['error' => 'unsupported_schema_version'];
        }
        $eventId = $raw['event_id'] ?? null;
        if (! is_string($eventId) || ! Str::isUuid($eventId)) {
            return ['error' => 'invalid_event_id'];
        }
        $appCode = strtoupper((string) ($raw['application_code'] ?? ''));
        $site = $this->sites->site($appCode);
        if (! $site) {
            return ['error' => 'unknown_application_code'];
        }
        // 収集器は登録されたサイトのイベントしか送れない（内部収集器は全サイト可）
        $collectorSite = $this->sites->siteById((int) $collector->site_id);
        $isInternal = $collectorSite && $collectorSite->application_code === 'INSIGHTS' && str_contains((string) $collector->node_code, '@');
        if (! $isInternal && (int) $collector->site_id !== (int) $site->id) {
            return ['error' => 'site_not_allowed_for_collector'];
        }
        $sourceKind = SourceKind::tryFrom((string) ($raw['source_kind'] ?? ''));
        if (! $sourceKind) {
            return ['error' => 'invalid_source_kind'];
        }
        $severity = Severity::tryFrom(strtoupper((string) ($raw['severity'] ?? '')));
        if (! $severity) {
            return ['error' => 'invalid_severity'];
        }
        $outcome = isset($raw['outcome']) ? Outcome::tryFrom((string) $raw['outcome']) : null;
        if ($sourceKind === SourceKind::CHECK && (! $outcome || empty($raw['check_key']) || empty($raw['run_id']))) {
            return ['error' => 'check_fields_required'];
        }
        try {
            $occurredAt = CarbonImmutable::parse((string) ($raw['occurred_at'] ?? ''))->utc();
        } catch (\Throwable) {
            return ['error' => 'invalid_occurred_at'];
        }
        $summary = Redactor::text(trim((string) ($raw['summary'] ?? '')));
        if ($summary === '') {
            return ['error' => 'summary_required'];
        }
        $summary = mb_substr($summary, 0, (int) $cfg['summary_max_chars']);
        $metrics = Redactor::array((array) ($raw['metrics'] ?? []));
        $evidence = Redactor::array((array) ($raw['evidence'] ?? []));
        $evidenceJson = CanonicalJson::encode($evidence);
        if (strlen($evidenceJson) > (int) $cfg['evidence_max_bytes']) {
            $evidence = ['_truncated' => true, 'note' => '証拠が16KiBを超えたため切り詰め', 'sample' => Redactor::truncate(CanonicalJson::encode($evidence), (int) $cfg['sample_max_bytes'])];
        }
        $businessDate = null;
        if (! empty($raw['business_date'])) {
            try {
                $businessDate = CarbonImmutable::parse((string) $raw['business_date'])->toDateString();
            } catch (\Throwable) {
                return ['error' => 'invalid_business_date'];
            }
        }
        $scope = Fingerprint::normalizeScope((array) ($raw['scope'] ?? []));
        $payloadHash = CanonicalJson::hash([
            'event_id' => $eventId, 'application_code' => $appCode, 'source_kind' => $sourceKind->value,
            'severity' => $severity->value, 'outcome' => $outcome?->value, 'occurred_at' => $occurredAt->format('Y-m-d\TH:i:s.u\Z'),
            'summary' => $summary, 'check_key' => $raw['check_key'] ?? null, 'error_code' => $raw['error_code'] ?? null,
            'business_date' => $businessDate, 'scope' => $scope,
        ]);

        return [
            'event_id' => $eventId,
            'payload_hash' => $payloadHash,
            'site' => $site,
            'application_code' => $appCode,
            'node_code' => mb_substr((string) ($raw['node_code'] ?? $collector->node_code), 0, 64),
            'source_kind' => $sourceKind,
            'severity' => $severity,
            'outcome' => $outcome,
            'check_key' => isset($raw['check_key']) ? mb_substr((string) $raw['check_key'], 0, 64) : null,
            'check_version' => $raw['check_version'] ?? null,
            'run_id' => $raw['run_id'] ?? null,
            'error_code' => isset($raw['error_code']) ? mb_substr((string) $raw['error_code'], 0, 64) : null,
            'business_date' => $businessDate,
            'scope' => $scope,
            'occurred_at' => $occurredAt,
            'summary' => $summary,
            'metrics' => $metrics,
            'evidence' => $evidence,
            'occurrence_delta' => max(1, (int) ($raw['occurrence_delta'] ?? 1)),
            'source_sequence' => isset($raw['source_sequence']) ? (int) $raw['source_sequence'] : null,
            'verification_complete' => (bool) ($raw['verification_complete'] ?? ($sourceKind !== SourceKind::CHECK)),
            'watermark' => $raw['source_watermark'] ?? null,
            'runbook_key' => $raw['runbook_key'] ?? null,
            'node_scoped' => (bool) ($raw['node_scoped'] ?? false),
            'title' => isset($raw['title']) ? mb_substr(Redactor::text((string) $raw['title']) ?? '', 0, 200) : null,
        ];
    }

    /** @return 'accepted'|'duplicate'|'conflict' */
    private function store(array $ev, Collector $collector): string
    {
        $existing = LogEntry::query()->where('collector_id', $collector->id)->where('event_id', $ev['event_id'])->first(['id', 'payload_hash']);
        if ($existing) {
            return hash_equals((string) $existing->payload_hash, $ev['payload_hash']) ? 'duplicate' : 'conflict';
        }
        $now = CarbonImmutable::now('UTC');
        /** @var Site $site */
        $site = $ev['site'];
        $severity = $ev['severity'];
        $retentionClass = $severity->retentionClass();
        $days = (int) config('monitoring.retention.'.strtolower($retentionClass).'_days', 30);

        $checkModel = null;
        if ($ev['check_key']) {
            $checkModel = \App\Models\Monitoring\Check::query()->where('site_id', $site->id)->where('check_key', $ev['check_key'])->first(['id', 'runbook_key', 'rule_json'])
                // 他サービスを観測するチェック（http.probe 等）は INSIGHTS 側の定義・ルールを使う
                ?? \App\Models\Monitoring\Check::query()->where('check_key', $ev['check_key'])->orderBy('id')->first(['id', 'runbook_key', 'rule_json']);
        }

        try {
            $log = LogEntry::query()->create([
                'collector_id' => $collector->id,
                'event_id' => $ev['event_id'],
                'payload_hash' => $ev['payload_hash'],
                'site_id' => $site->id,
                'node_code' => $ev['node_code'],
                'check_id' => $checkModel?->id,
                'run_id' => is_numeric($ev['run_id']) ? (int) $ev['run_id'] : null,
                'source_kind' => $ev['source_kind'],
                'severity' => $severity,
                'outcome' => $ev['outcome']?->value,
                'check_key' => $ev['check_key'],
                'error_code' => $ev['error_code'],
                'business_date' => $ev['business_date'],
                'occurred_at' => $ev['occurred_at'],
                'received_at' => $now,
                'summary' => $ev['summary'],
                'metrics_json' => $ev['metrics'] ?: null,
                'evidence_json' => $ev['evidence'] ?: null,
                'occurrence_delta' => $ev['occurrence_delta'],
                'retention_class' => $retentionClass,
                'expires_at' => $now->addDays($days),
            ]);
        } catch (QueryException $e) {
            $existing = LogEntry::query()->where('collector_id', $collector->id)->where('event_id', $ev['event_id'])->first(['payload_hash']);
            if ($existing) {
                return hash_equals((string) $existing->payload_hash, $ev['payload_hash']) ? 'duplicate' : 'conflict';
            }
            throw $e;
        }

        $this->counters->bump((int) $site->id, $severity, $ev['outcome'], $now);

        $definition = $ev['check_key'] ? ($this->checks->definition($site->application_code, $ev['check_key']) ?? $this->checks->definitionByKey($ev['check_key'])) : null;
        $includeDate = $definition?->includesBusinessDateInFingerprint ?? ($ev['business_date'] !== null);
        $requiredStreak = $definition?->recoveryHealthyStreak ?? 2;
        $runbookKey = $ev['runbook_key'] ?? $checkModel?->runbook_key ?? $definition?->runbookKey;

        // 違反でも重大度が WARNING（監視ルールで下げた等）の場合はエラー管理に上げない
        $isError = $severity->isErrorOrAbove();
        // 監視ルール「一致したときの動作: 記録のみ」はログだけ残してエラー管理・通知へ上げない
        if ($isError && ($checkModel?->rule_json['action'] ?? 'notify') === 'record_only') {
            $isError = false;
        }
        if ($isError) {
            $r = $this->incidents->recordActive([
                'site_id' => $site->id,
                'application_code' => $site->application_code,
                'check_id' => $checkModel?->id,
                'check_key' => $ev['check_key'],
                'error_code' => $ev['error_code'],
                'scope' => $ev['scope'],
                'business_date' => $ev['business_date'],
                'include_date' => $includeDate,
                'node_code' => $ev['node_code'],
                'node_scoped' => $ev['node_scoped'],
                'severity' => $severity->isErrorOrAbove() ? $severity : Severity::ERROR,
                'title' => $ev['title'] ?: $ev['summary'],
                'evidence' => $ev['evidence'],
                'metrics' => $ev['metrics'],
                'occurred_at' => $ev['occurred_at'],
                'occurrence_delta' => $ev['occurrence_delta'],
                'source_sequence' => $ev['source_sequence'] ?? (is_numeric($ev['run_id']) ? (int) $ev['run_id'] : null),
                'runbook_key' => $runbookKey,
            ]);
            $log->incident_id = $r['incident']->id;
            $log->save();
            // 統計: 新規インシデント / 既存への集約（処理フローの「重複抑制」）
            $this->counters->add((int) $site->id, CounterService::KEY_SEVERITY_HOURLY, $now, [$r['created'] ? 'incident_new' : 'deduplicated' => 1]);
        } elseif ($ev['source_kind'] === SourceKind::CHECK && $ev['check_key']) {
            if ($ev['outcome'] === Outcome::HEALTHY) {
                $inc = $this->incidents->recordHealthy(
                    $site->application_code, $ev['check_key'], $ev['scope'], $ev['business_date'], $includeDate,
                    $requiredStreak, is_numeric($ev['run_id']) ? (int) $ev['run_id'] : $ev['source_sequence'], $now, $ev['verification_complete'],
                );
                if ($inc) {
                    $log->incident_id = $inc->id;
                    $log->save();
                }
            } elseif ($ev['outcome'] === Outcome::UNKNOWN) {
                $inc = $this->incidents->recordUnknown($site->application_code, $ev['check_key'], $ev['scope'], $ev['business_date'], $includeDate, $now);
                if ($inc) {
                    $log->incident_id = $inc->id;
                    $log->save();
                }
            }
        }

        return 'accepted';
    }
}
