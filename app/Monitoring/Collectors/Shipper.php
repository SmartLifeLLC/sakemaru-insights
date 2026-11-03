<?php

namespace App\Monitoring\Collectors;

use App\Models\Monitoring\Collector;
use App\Monitoring\Services\ObservationIngestor;
use App\Monitoring\Services\SiteRegistry;
use App\Monitoring\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * 退避ファイルを取込先へ送信し、ACK 済みだけを解放する（仕様書 6.2）。
 * MONITOR_INGEST_URL が未設定なら同一ホストで直接取込む。設定時は HMAC 署名付き HTTP。
 */
class Shipper
{
    public function __construct(private readonly SiteRegistry $sites, private readonly ObservationIngestor $ingestor) {}

    /** @return array{accepted:int,duplicate:int,rejected:int,conflict:int,files:int} */
    public function ship(array $collectorConfig, Spool $spool): array
    {
        $stats = ['accepted' => 0, 'duplicate' => 0, 'rejected' => 0, 'conflict' => 0, 'files' => 0];
        $maxEvents = (int) config('monitoring.ingest.max_batch_events', 100);
        $maxBytes = (int) config('monitoring.ingest.max_batch_bytes', 262144);
        foreach ($spool->files() as $file) {
            $events = $spool->read($file);
            if (! $events) {
                @unlink($file);

                continue;
            }
            $stats['files']++;
            $acked = [];
            $batch = [];
            $bytes = 0;
            $flush = function () use (&$batch, &$bytes, &$acked, &$stats, $collectorConfig) {
                if (! $batch) {
                    return;
                }
                $r = $this->send($collectorConfig, $batch);
                $acked = array_merge($acked, $r['accepted'], $r['duplicate'], $r['conflict']);
                $stats['accepted'] += count($r['accepted']);
                $stats['duplicate'] += count($r['duplicate']);
                $stats['conflict'] += count($r['conflict']);
                $stats['rejected'] += count($r['rejected']);
                // 永続的な拒否（形式不正など）は ACK 扱いで解放し、store_failed は退避に残す
                foreach ($r['rejected'] as $rej) {
                    if ($rej['event_id'] && ($rej['reason'] ?? '') !== 'store_failed' && ($rej['reason'] ?? '') !== 'transport') {
                        $acked[] = $rej['event_id'];
                    }
                }
                $batch = [];
                $bytes = 0;
            };
            foreach ($events as $e) {
                $len = strlen(CanonicalJson::encode($e));
                if ($batch && (count($batch) >= $maxEvents || $bytes + $len > $maxBytes)) {
                    $flush();
                }
                $batch[] = $e;
                $bytes += $len;
            }
            $flush();
            $spool->release($file, $acked);
        }

        return $stats;
    }

    /** @return array{accepted:string[],duplicate:string[],rejected:array<int,array{event_id:?string,reason:string}>,conflict:string[]} */
    public function send(array $collectorConfig, array $events): array
    {
        $url = config('monitoring.collector.ingest_url');
        if (! $url) {
            $collector = $this->sites->findCollectorByUuid($collectorConfig['collector_uuid']);
            if (! $collector) {
                $this->sites->sync();
                $collector = $this->sites->findCollectorByUuid($collectorConfig['collector_uuid']);
            }
            if (! $collector) {
                return ['accepted' => [], 'duplicate' => [], 'rejected' => array_map(fn ($e) => ['event_id' => $e['event_id'] ?? null, 'reason' => 'transport'], $events), 'conflict' => []];
            }
            $this->touchHeartbeat($collector, $collectorConfig);

            return $this->ingestor->ingest($events, $collector);
        }
        $body = CanonicalJson::encode(['collector_uuid' => $collectorConfig['collector_uuid'], 'events' => $events]);
        $ts = (string) time();
        $secret = (string) \App\Monitoring\Support\Env::get($collectorConfig['secret_env'] ?? null);
        $sig = hash_hmac('sha256', $ts.'.'.$body, $secret);
        try {
            $resp = Http::timeout(15)->withHeaders([
                'X-Monitor-Collector' => $collectorConfig['collector_uuid'],
                'X-Monitor-Timestamp' => $ts,
                'X-Monitor-Signature' => $sig,
            ])->withBody($body, 'application/json')->post(rtrim($url, '/').'/internal/monitor/events');
        } catch (\Throwable) {
            return ['accepted' => [], 'duplicate' => [], 'rejected' => array_map(fn ($e) => ['event_id' => $e['event_id'] ?? null, 'reason' => 'transport'], $events), 'conflict' => []];
        }
        if (! in_array($resp->status(), [200, 207, 409], true)) {
            return ['accepted' => [], 'duplicate' => [], 'rejected' => array_map(fn ($e) => ['event_id' => $e['event_id'] ?? null, 'reason' => 'transport'], $events), 'conflict' => []];
        }
        $j = $resp->json();

        return [
            'accepted' => (array) ($j['accepted'] ?? []),
            'duplicate' => (array) ($j['duplicate'] ?? []),
            'rejected' => (array) ($j['rejected'] ?? []),
            'conflict' => (array) ($j['conflict'] ?? []),
        ];
    }

    public function heartbeat(array $collectorConfig, array $state = []): bool
    {
        $url = config('monitoring.collector.ingest_url');
        if (! $url) {
            $collector = $this->sites->findCollectorByUuid($collectorConfig['collector_uuid']);
            if (! $collector) {
                return false;
            }
            $this->touchHeartbeat($collector, $collectorConfig, $state);

            return true;
        }
        $body = CanonicalJson::encode(['collector_uuid' => $collectorConfig['collector_uuid'], 'state' => $state]);
        $ts = (string) time();
        $sig = hash_hmac('sha256', $ts.'.'.$body, (string) \App\Monitoring\Support\Env::get($collectorConfig['secret_env'] ?? null));
        try {
            return Http::timeout(10)->withHeaders(['X-Monitor-Collector' => $collectorConfig['collector_uuid'], 'X-Monitor-Timestamp' => $ts, 'X-Monitor-Signature' => $sig])
                ->withBody($body, 'application/json')->post(rtrim($url, '/').'/internal/monitor/heartbeat')->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function touchHeartbeat(Collector $collector, array $cfg, array $state = []): void
    {
        $now = CarbonImmutable::now('UTC');
        // 60秒単位で更新（イベントごとに共有1行を更新しない）
        if ($collector->last_heartbeat_at && CarbonImmutable::instance($collector->last_heartbeat_at)->diffInSeconds($now) < 60 && ! $state) {
            return;
        }
        Collector::query()->where('id', $collector->id)->update([
            'last_heartbeat_at' => $now,
            'status' => 'ACTIVE',
            'version' => $cfg['version'] ?? '1',
            'state_json' => $state ? json_encode($state, JSON_UNESCAPED_UNICODE) : $collector->state_json,
        ]);
    }
}
