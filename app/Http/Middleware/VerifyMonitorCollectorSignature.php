<?php

namespace App\Http\Middleware;

use App\Monitoring\Services\SiteRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 取込 API の HMAC 認証（仕様書 17）。collector ごとの秘密鍵、timestamp 許容 ±5 分。
 * 別配置の収集認証鍵を流用できない（collector_uuid と鍵の対応は配置設定に固定）。
 */
class VerifyMonitorCollectorSignature
{
    public function __construct(private readonly SiteRegistry $sites) {}

    public function handle(Request $request, Closure $next): Response
    {
        $uuid = (string) $request->header('X-Monitor-Collector', '');
        $ts = (string) $request->header('X-Monitor-Timestamp', '');
        $sig = (string) $request->header('X-Monitor-Signature', '');
        $cfg = $uuid ? $this->sites->collectorConfig($uuid) : null;
        if (! $cfg || ! ctype_digit($ts) || $sig === '') {
            return response()->json(['error' => 'unauthorized'], 401);
        }
        $tolerance = (int) config('monitoring.ingest.timestamp_tolerance_seconds', 300);
        if (abs(time() - (int) $ts) > $tolerance) {
            return response()->json(['error' => 'timestamp_out_of_range'], 401);
        }
        $secret = (string) \App\Monitoring\Support\Env::get($cfg['secret_env'] ?? null);
        if ($secret === '') {
            return response()->json(['error' => 'collector_secret_not_configured'], 401);
        }
        $expected = hash_hmac('sha256', $ts.'.'.$request->getContent(), $secret);
        if (! hash_equals($expected, $sig)) {
            return response()->json(['error' => 'bad_signature'], 401);
        }
        $collector = $this->sites->findCollectorByUuid($uuid);
        if (! $collector) {
            $this->sites->sync();
            $collector = $this->sites->findCollectorByUuid($uuid);
        }
        if (! $collector) {
            return response()->json(['error' => 'collector_not_registered'], 401);
        }
        $request->attributes->set('monitor_collector', $collector);
        $request->attributes->set('monitor_collector_config', $cfg);

        return $next($request);
    }
}
