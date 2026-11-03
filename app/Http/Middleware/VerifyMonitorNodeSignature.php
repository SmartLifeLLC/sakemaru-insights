<?php

namespace App\Http\Middleware;

use App\Monitoring\Resources\MetricsIngestService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 資源データ API の HMAC 認証。同じ顧客（配置）の全ノードで共有する鍵（MONITOR_METRICS_SECRET）を使う。
 * ノードは事前登録不要（初回送信で自動登録）のため、鍵は配置単位。ヘッダのノードコードと本文のノードコードの一致を必須にする。
 */
class VerifyMonitorNodeSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $node = (string) $request->header('X-Monitor-Node', '');
        $ts = (string) $request->header('X-Monitor-Timestamp', '');
        $sig = (string) $request->header('X-Monitor-Signature', '');
        if (! preg_match(MetricsIngestService::NODE_CODE_PATTERN, $node) || ! ctype_digit($ts) || $sig === '') {
            return response()->json(['error' => 'unauthorized'], 401);
        }
        if (abs(time() - (int) $ts) > (int) config('monitoring.ingest.timestamp_tolerance_seconds', 300)) {
            return response()->json(['error' => 'timestamp_out_of_range'], 401);
        }
        $secret = (string) \App\Monitoring\Support\Env::get((string) config('monitoring.resources.secret_env', 'MONITOR_METRICS_SECRET'));
        if ($secret === '') {
            return response()->json(['error' => 'metrics_secret_not_configured'], 401);
        }
        if (! hash_equals(hash_hmac('sha256', $ts.'.'.$request->getContent(), $secret), $sig)) {
            return response()->json(['error' => 'bad_signature'], 401);
        }
        if ((string) $request->json('node.code') !== $node) {
            return response()->json(['error' => 'node_mismatch'], 401);
        }
        if (strlen($request->getContent()) > 2 * 1024 * 1024) {
            return response()->json(['error' => 'payload_too_large'], 413);
        }

        return $next($request);
    }
}
