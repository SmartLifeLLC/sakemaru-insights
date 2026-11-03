<?php

namespace App\Monitoring\Resources;

use App\Monitoring\Support\CanonicalJson;
use Illuminate\Support\Facades\Http;

/**
 * 資源データの送信（収集側）。ingest_url 未設定なら同一ホストへ直接保存する。
 * 送れなかった 5 分集約は状態ファイルに退避し、次回まとめて再送する（最大 7 日分）。秘密鍵は env 参照名だけを設定に持つ。
 */
class MetricsShipper
{
    private const MAX_PENDING = 2016;

    public function __construct(private readonly MetricsIngestService $ingest) {}

    private function pendingPath(string $node): string
    {
        $dir = rtrim((string) config('monitoring.collector.state_dir'), '/');
        @mkdir($dir, 0750, true);

        return $dir.'/metrics-pending-'.preg_replace('/[^A-Za-z0-9_.-]/', '_', $node).'.json';
    }

    /** @return array<int,array> */
    public function pending(string $node): array
    {
        $raw = @file_get_contents($this->pendingPath($node));
        $v = $raw ? json_decode($raw, true) : [];

        return is_array($v) ? $v : [];
    }

    private function savePending(string $node, array $aggs): void
    {
        $aggs = array_slice(array_values($aggs), -self::MAX_PENDING);
        $path = $this->pendingPath($node);
        if (! $aggs) {
            @unlink($path);

            return;
        }
        file_put_contents($path.'.tmp', json_encode($aggs), LOCK_EX);
        rename($path.'.tmp', $path);
    }

    /**
     * @param  array<int,array>  $newAggregates
     * @return array{ok:bool,sent:int,pending:int,thresholds:?array}
     */
    public function ship(array $node, array $latest, array $newAggregates = []): array
    {
        $aggs = array_merge($this->pending($node['code']), $newAggregates);
        $payload = ['node' => $node, 'latest' => $latest, 'aggregates' => $aggs];
        $url = config('monitoring.collector.ingest_url');
        try {
            if (! $url) {
                $r = $this->ingest->ingest($payload);
            } else {
                $body = CanonicalJson::encode($payload);
                $ts = (string) time();
                $secret = (string) \App\Monitoring\Support\Env::get((string) config('monitoring.resources.secret_env', 'MONITOR_METRICS_SECRET'));
                if ($secret === '') {
                    throw new \RuntimeException('metrics_secret_not_configured');
                }
                $resp = Http::timeout(10)->withHeaders([
                    'X-Monitor-Node' => $node['code'], 'X-Monitor-Timestamp' => $ts,
                    'X-Monitor-Signature' => hash_hmac('sha256', $ts.'.'.$body, $secret),
                ])->withBody($body, 'application/json')->post(rtrim($url, '/').'/internal/monitor/metrics');
                if (! $resp->successful()) {
                    throw new \RuntimeException('http_'.$resp->status());
                }
                $r = (array) $resp->json();
            }
        } catch (\Throwable) {
            $this->savePending($node['code'], $aggs);

            return ['ok' => false, 'sent' => 0, 'pending' => count($aggs), 'thresholds' => null];
        }
        $this->savePending($node['code'], []);

        return ['ok' => true, 'sent' => count($aggs), 'pending' => 0, 'thresholds' => $r['thresholds'] ?? null];
    }
}
