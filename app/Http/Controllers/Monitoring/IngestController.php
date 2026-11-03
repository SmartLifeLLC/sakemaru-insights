<?php

namespace App\Http\Controllers\Monitoring;

use App\Http\Controllers\Controller;
use App\Models\Monitoring\Collector;
use App\Monitoring\Collectors\Shipper;
use App\Monitoring\Services\ObservationIngestor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /internal/monitor/events, /internal/monitor/heartbeat（仕様書 第18章）。
 * 失敗時に HTTP 200 と空配列を返してデータ消失を隠さない。
 */
class IngestController extends Controller
{
    public function events(Request $request, ObservationIngestor $ingestor, Shipper $shipper): JsonResponse
    {
        /** @var Collector $collector */
        $collector = $request->attributes->get('monitor_collector');
        $max = (int) config('monitoring.ingest.max_batch_bytes', 262144);
        if (strlen($request->getContent()) > $max) {
            return response()->json(['error' => 'payload_too_large', 'max_bytes' => $max], 413);
        }
        $events = $request->json('events');
        if (! is_array($events)) {
            return response()->json(['error' => 'events_required'], 422);
        }
        if (count($events) > (int) config('monitoring.ingest.max_batch_events', 100)) {
            return response()->json(['error' => 'batch_too_large'], 422);
        }
        $shipper->touchHeartbeat($collector, (array) $request->attributes->get('monitor_collector_config'));
        $result = $ingestor->ingest($events, $collector);
        $storeFailed = (bool) array_filter($result['rejected'], fn ($r) => ($r['reason'] ?? '') === 'store_failed');
        $status = $storeFailed ? 503 : ($result['conflict'] ? 409 : ($result['rejected'] ? 207 : 200));

        return response()->json($result, $status);
    }

    public function heartbeat(Request $request, Shipper $shipper): JsonResponse
    {
        /** @var Collector $collector */
        $collector = $request->attributes->get('monitor_collector');
        $shipper->touchHeartbeat($collector, (array) $request->attributes->get('monitor_collector_config'), (array) $request->json('state', []));

        return response()->json(['ok' => true, 'server_time' => now('UTC')->toIso8601String()]);
    }

    public function metrics(Request $request, \App\Monitoring\Resources\MetricsIngestService $service): JsonResponse
    {
        try {
            $r = $service->ingest((array) $request->json()->all());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true] + $r);
    }
}
