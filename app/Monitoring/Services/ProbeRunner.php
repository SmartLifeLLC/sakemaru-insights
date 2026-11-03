<?php

namespace App\Monitoring\Services;

use App\Models\Monitoring\ProbeResult;
use App\Models\Monitoring\Target;
use App\Monitoring\Enums\TargetKind;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * HTTP 外形監視（v2 第3章 / V-050〜055）。登録済み URL だけを並列に GET し、結果を probe_results と probe_hourly に記録する。
 * リダイレクトは追わない（別ホストへ飛ばない）。本文は読まない。
 */
class ProbeRunner
{
    public function __construct(private readonly FeatureRegistry $features, private readonly CounterService $counters) {}

    /** @return array<int,array{target:Target,result:ProbeResult,consecutive_failures:int}> */
    public function run(CarbonImmutable $now): array
    {
        $targets = Target::query()->where('kind', TargetKind::HTTP_PROBE->value)->where('enabled', true)->orderBy('id')->get()
            ->filter(fn (Target $t) => $this->features->targetActive($t))->values();
        if ($targets->isEmpty()) {
            return [];
        }
        $timeout = (int) config('monitoring.probe.timeout_seconds', 5);
        $connect = (int) config('monitoring.probe.connect_timeout_seconds', 3);
        $ua = (string) config('monitoring.probe.user_agent', 'SakemaruInsightsProbe/1.0');

        $urls = [];
        $blocked = [];
        foreach ($targets as $i => $t) {
            try {
                $urls[$i] = TargetService::validateProbeUrl((string) ($t->params_json['url'] ?? ''));
            } catch (\InvalidArgumentException) {
                $blocked[$i] = true; // 登録後に許可ホストから外れた URL は叩かない
            }
        }
        $started = microtime(true);
        $responses = $urls ? Http::pool(function (Pool $pool) use ($urls, $timeout, $connect, $ua) {
            $reqs = [];
            foreach ($urls as $i => $url) {
                $reqs[] = $pool->as((string) $i)->withUserAgent($ua)->timeout($timeout)->connectTimeout($connect)->withoutRedirecting()
                    ->withHeaders(['Accept' => 'text/plain, application/json', 'Cache-Control' => 'no-cache'])->get($url);
            }

            return $reqs;
        }) : [];
        $elapsedMs = (int) round((microtime(true) - $started) * 1000);

        $expires = $now->addDays((int) config('monitoring.probe.results_days', 30));
        $out = [];
        foreach ($targets as $i => $t) {
            $expect = (int) ($t->params_json['expect_status'] ?? 200);
            $status = null;
            $duration = null;
            $error = null;
            if (isset($blocked[$i])) {
                $error = 'URL_NOT_ALLOWED';
            } else {
                $r = $responses[(string) $i] ?? null;
                if ($r instanceof Response) {
                    $status = $r->status();
                    $stats = $r->handlerStats();
                    $duration = isset($stats['total_time']) ? (int) round($stats['total_time'] * 1000) : $elapsedMs;
                    if ($status !== $expect && ! ($expect === 200 && $status >= 200 && $status < 300)) {
                        $error = $status >= 300 && $status < 400 ? 'HTTP_REDIRECT' : 'HTTP_STATUS';
                    }
                } elseif ($r instanceof ConnectionException || $r instanceof \Throwable) {
                    $msg = strtolower($r->getMessage());
                    $error = str_contains($msg, 'timed out') || str_contains($msg, 'timeout') ? 'TIMEOUT' : (str_contains($msg, 'ssl') || str_contains($msg, 'certificate') ? 'TLS' : (str_contains($msg, 'resolve') ? 'DNS' : 'CONNECT'));
                    $duration = str_starts_with($error, 'TIMEOUT') ? $timeout * 1000 : null;
                } else {
                    $error = 'NO_RESPONSE';
                }
            }
            $ok = $error === null;
            $result = ProbeResult::query()->create([
                'target_id' => $t->id, 'site_id' => $t->site_id, 'checked_at' => $now, 'http_status' => $status,
                'duration_ms' => $duration, 'ok' => $ok, 'error_code' => $error, 'expires_at' => $expires,
            ]);
            if ($t->site_id) {
                $this->counters->add((int) $t->site_id, CounterService::KEY_PROBE_HOURLY, $now,
                    ['n' => 1, 'fail' => $ok ? 0 : 1, 'sum_ms' => (int) ($duration ?? 0), 'timed' => $duration === null ? 0 : 1],
                    $duration === null ? [] : ['max_ms' => $duration]);
            }
            $out[] = ['target' => $t, 'result' => $result, 'consecutive_failures' => $ok ? 0 : $this->consecutiveFailures($t)];
        }
        $this->counters->flush();

        return $out;
    }

    private function consecutiveFailures(Target $t): int
    {
        $n = 0;
        $limit = max(1, (int) config('monitoring.probe.error_after_failures', 3));
        foreach (ProbeResult::query()->where('target_id', $t->id)->orderByDesc('id')->limit($limit)->pluck('ok') as $ok) {
            if ($ok) {
                break;
            }
            $n++;
        }

        return $n;
    }

    /**
     * サービス別の可用性・平均応答時間（probe_hourly から。V-054）。
     *
     * @return array<int,array{n:int,fail:int,availability:?float,avg_ms:?int,max_ms:?int}>
     */
    public function availabilityBySite(int $hours = 24): array
    {
        $since = CarbonImmutable::now('UTC')->subHours($hours - 1)->startOfHour();
        $out = [];
        foreach (\App\Models\Monitoring\Counter::query()->where('counter_key', CounterService::KEY_PROBE_HOURLY)->where('bucket_start', '>=', $since)->get() as $r) {
            $sid = (int) $r->site_id;
            $c = (array) $r->counts_json;
            $out[$sid] ??= ['n' => 0, 'fail' => 0, 'sum_ms' => 0, 'timed' => 0, 'max_ms' => null];
            foreach (['n', 'fail', 'sum_ms', 'timed'] as $k) {
                $out[$sid][$k] += (int) ($c[$k] ?? 0);
            }
            if (isset($c['max_ms'])) {
                $out[$sid]['max_ms'] = max((int) $out[$sid]['max_ms'], (int) $c['max_ms']);
            }
        }
        foreach ($out as $sid => $v) {
            $out[$sid] = ['n' => $v['n'], 'fail' => $v['fail'], 'availability' => $v['n'] ? round(($v['n'] - $v['fail']) / $v['n'] * 100, 2) : null,
                'avg_ms' => $v['timed'] ? (int) round($v['sum_ms'] / $v['timed']) : null, 'max_ms' => $v['max_ms']];
        }

        return $out;
    }
}
