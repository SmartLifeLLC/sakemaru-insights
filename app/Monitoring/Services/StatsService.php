<?php

namespace App\Monitoring\Services;

use App\Models\Monitoring\Counter;
use Carbon\CarbonImmutable;

/**
 * ダッシュボード・統計画面の集計（v2 第6.3 / V-080〜086）。元データは ins_monitor_counters（時間別）だけ。
 * ログ・インシデントを全件 COUNT しない。業務DB には触れない。
 */
class StatsService
{
    public const PERIODS = ['24h' => 24, '7d' => 168, '30d' => 720];

    public function __construct(private readonly SiteRegistry $sites) {}

    public static function hours(string $period): int
    {
        return self::PERIODS[$period] ?? 24;
    }

    /**
     * 期間の [開始, 終了)（時間境界）。終了は「今の時間の次の境界」。前期間は同じ長さで直前。
     *
     * @return array{0:CarbonImmutable,1:CarbonImmutable}
     */
    public static function window(string $period, ?CarbonImmutable $now = null, int $shift = 0): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $h = self::hours($period);
        $end = $now->utc()->startOfHour()->addHour()->subHours($h * $shift);

        return [$end->subHours($h), $end];
    }

    /**
     * @param  int[]|null  $siteIds
     * @return array<string,array<string,float|int>> counter_key => 合計
     */
    private function sums(CarbonImmutable $from, CarbonImmutable $to, ?array $siteIds, array $keys): array
    {
        $q = Counter::query()->whereIn('counter_key', $keys)->where('bucket_start', '>=', $from)->where('bucket_start', '<', $to);
        if ($siteIds) {
            $q->whereIn('site_id', $siteIds);
        }
        $out = array_fill_keys($keys, []);
        foreach ($q->get(['counter_key', 'counts_json']) as $r) {
            foreach ((array) $r->counts_json as $k => $v) {
                if (str_ends_with((string) $k, 'max_ms')) {
                    $out[$r->counter_key][$k] = max($out[$r->counter_key][$k] ?? 0, (float) $v);
                } else {
                    $out[$r->counter_key][$k] = ($out[$r->counter_key][$k] ?? 0) + $v;
                }
            }
        }

        return $out;
    }

    /**
     * KPI（今回と前期間）。正常判定率 = HEALTHY ÷（HEALTHY + WARNING + VIOLATION + UNKNOWN）。NOT_DUE と未監視は分母に入れない（V-082）。
     *
     * @return array{current:array,previous:array,delta:array}
     */
    public function kpis(string $period = '24h', ?array $siteIds = null, ?CarbonImmutable $now = null): array
    {
        $cur = $this->kpiFor(...[...self::window($period, $now), $siteIds]);
        $prev = $this->kpiFor(...[...self::window($period, $now, 1), $siteIds]);
        $delta = [];
        foreach ($cur as $k => $v) {
            $p = $prev[$k] ?? null;
            $delta[$k] = is_numeric($v) && is_numeric($p) ? round($v - $p, 2) : null;
        }

        return ['current' => $cur, 'previous' => $prev, 'delta' => $delta];
    }

    private function kpiFor(CarbonImmutable $from, CarbonImmutable $to, ?array $siteIds): array
    {
        $s = $this->sums($from, $to, $siteIds, [CounterService::KEY_SEVERITY_HOURLY, CounterService::KEY_NOTIFICATION_HOURLY, CounterService::KEY_PROBE_HOURLY]);
        $sev = $s[CounterService::KEY_SEVERITY_HOURLY];
        $ntf = $s[CounterService::KEY_NOTIFICATION_HOURLY];
        $prb = $s[CounterService::KEY_PROBE_HOURLY];
        $healthy = (int) ($sev['outcome_HEALTHY'] ?? 0);
        $judged = $healthy + (int) ($sev['outcome_WARNING'] ?? 0) + (int) ($sev['outcome_VIOLATION'] ?? 0) + (int) ($sev['outcome_UNKNOWN'] ?? 0);
        $delivered = (int) ($ntf['delivered'] ?? 0);
        $failed = (int) ($ntf['blocked'] ?? 0);

        return [
            'events' => (int) (($sev['INFO'] ?? 0) + ($sev['WARNING'] ?? 0) + ($sev['ERROR'] ?? 0) + ($sev['CRITICAL'] ?? 0)),
            'info' => (int) ($sev['INFO'] ?? 0),
            'warning' => (int) ($sev['WARNING'] ?? 0),
            'error' => (int) ($sev['ERROR'] ?? 0),
            'critical' => (int) ($sev['CRITICAL'] ?? 0),
            'checks_judged' => $judged,
            'healthy_rate' => $judged ? round($healthy / $judged * 100, 2) : null,
            'incidents_new' => (int) ($sev['incident_new'] ?? 0),
            'deduplicated' => (int) ($sev['deduplicated'] ?? 0),
            'notifications_queued' => (int) ($ntf['queued'] ?? 0),
            'notifications_delivered' => $delivered,
            'notifications_failed' => $failed,
            'delivery_rate' => ($delivered + $failed) ? round($delivered / ($delivered + $failed) * 100, 2) : null,
            'probe_availability' => ($prb['n'] ?? 0) ? round(((int) $prb['n'] - (int) ($prb['fail'] ?? 0)) / (int) $prb['n'] * 100, 2) : null,
            'probe_avg_ms' => ($prb['timed'] ?? 0) ? (int) round($prb['sum_ms'] / $prb['timed']) : null,
            'outcome_healthy' => $healthy,
            'outcome_warning' => (int) ($sev['outcome_WARNING'] ?? 0),
            'outcome_violation' => (int) ($sev['outcome_VIOLATION'] ?? 0),
            'outcome_unknown' => (int) ($sev['outcome_UNKNOWN'] ?? 0),
            'outcome_not_due' => (int) ($sev['outcome_NOT_DUE'] ?? 0),
        ];
    }

    /**
     * 処理フロー（V-084）: 受信 → 異常 → インシデント → 通知対象 → 配送完了 / 失敗。重複抑制 = 既存インシデントへ集約した件数。
     *
     * @return array<string,int>
     */
    public function flow(string $period = '24h', ?array $siteIds = null, ?CarbonImmutable $now = null): array
    {
        $k = $this->kpiFor(...[...self::window($period, $now), $siteIds]);

        return [
            'received' => $k['events'],
            'anomalies' => $k['warning'] + $k['error'] + $k['critical'],
            'incidents' => $k['incidents_new'],
            'deduplicated' => $k['deduplicated'],
            'queued' => $k['notifications_queued'],
            'delivered' => $k['notifications_delivered'],
            'failed' => $k['notifications_failed'],
        ];
    }

    /**
     * 時間別の推移（重大度別）。期間が 7 日以上なら 6 時間ごと、30 日なら 1 日ごとにまとめる。
     *
     * @return array<int,array{ts:int,INFO:int,WARNING:int,ERROR:int,CRITICAL:int}>
     */
    public function series(string $period = '24h', ?array $siteIds = null, ?CarbonImmutable $now = null): array
    {
        [$from, $to] = self::window($period, $now);
        $step = match (self::hours($period)) {
            168 => 6,
            720 => 24,
            default => 1,
        };
        $buckets = [];
        for ($t = $from; $t->lessThan($to); $t = $t->addHours($step)) {
            $buckets[$t->getTimestamp()] = ['ts' => $t->getTimestamp(), 'INFO' => 0, 'WARNING' => 0, 'ERROR' => 0, 'CRITICAL' => 0];
        }
        $q = Counter::query()->where('counter_key', CounterService::KEY_SEVERITY_HOURLY)->where('bucket_start', '>=', $from)->where('bucket_start', '<', $to);
        if ($siteIds) {
            $q->whereIn('site_id', $siteIds);
        }
        foreach ($q->get(['bucket_start', 'counts_json']) as $r) {
            $ts = CarbonImmutable::instance($r->bucket_start)->getTimestamp();
            $b = $from->getTimestamp() + intdiv($ts - $from->getTimestamp(), $step * 3600) * $step * 3600;
            if (! isset($buckets[$b])) {
                continue;
            }
            foreach (['INFO', 'WARNING', 'ERROR', 'CRITICAL'] as $sev) {
                $buckets[$b][$sev] += (int) ($r->counts_json[$sev] ?? 0);
            }
        }

        return array_values($buckets);
    }

    /**
     * 曜日 × 2 時間のヒートマップ（JST、V-083）。値 = 警告＋エラー＋重大の件数。
     *
     * @return array{cells:array<int,array<int,int>>,max:int} cells[dow 0=日..6][slot 0..11]
     */
    public function heatmap(string $period = '30d', ?array $siteIds = null, ?CarbonImmutable $now = null): array
    {
        [$from, $to] = self::window($period, $now);
        $cells = array_fill(0, 7, array_fill(0, 12, 0));
        $q = Counter::query()->where('counter_key', CounterService::KEY_SEVERITY_HOURLY)->where('bucket_start', '>=', $from)->where('bucket_start', '<', $to);
        if ($siteIds) {
            $q->whereIn('site_id', $siteIds);
        }
        foreach ($q->get(['bucket_start', 'counts_json']) as $r) {
            $jst = CarbonImmutable::instance($r->bucket_start)->setTimezone('Asia/Tokyo');
            $v = (int) (($r->counts_json['WARNING'] ?? 0) + ($r->counts_json['ERROR'] ?? 0) + ($r->counts_json['CRITICAL'] ?? 0));
            $cells[$jst->dayOfWeek][intdiv($jst->hour, 2)] += $v;
        }

        return ['cells' => $cells, 'max' => max(array_map('max', $cells))];
    }

    /**
     * サービス別の稼働バー（直近 24 時間、1 時間 1 本、V-096）。各時間の最悪の判定:
     * violation > warning > unknown > healthy。判定が無い時間は none（未監視・判定なし）。
     *
     * @return array<int,array<int,array{ts:int,state:string,counts:array}>> site_id => 24 本
     */
    public function serviceBars(?CarbonImmutable $now = null, int $hours = 24): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $from = $now->utc()->startOfHour()->subHours($hours - 1);
        $rows = Counter::query()->where('counter_key', CounterService::KEY_SEVERITY_HOURLY)->where('bucket_start', '>=', $from)->get(['site_id', 'bucket_start', 'counts_json']);
        $by = [];
        foreach ($rows as $r) {
            $by[(int) $r->site_id][CarbonImmutable::instance($r->bucket_start)->getTimestamp()] = (array) $r->counts_json;
        }
        $out = [];
        foreach ($this->sites->all() as $site) {
            $bars = [];
            for ($i = 0; $i < $hours; $i++) {
                $ts = $from->addHours($i)->getTimestamp();
                $c = $by[(int) $site->id][$ts] ?? [];
                $state = match (true) {
                    ($c['outcome_VIOLATION'] ?? 0) > 0 || ($c['CRITICAL'] ?? 0) > 0 || ($c['ERROR'] ?? 0) > 0 => 'violation',
                    ($c['outcome_WARNING'] ?? 0) > 0 || ($c['WARNING'] ?? 0) > 0 => 'warning',
                    ($c['outcome_UNKNOWN'] ?? 0) > 0 => 'unknown',
                    ($c['outcome_HEALTHY'] ?? 0) > 0 => 'healthy',
                    default => 'none',
                };
                $bars[] = ['ts' => $ts, 'state' => $state, 'counts' => array_intersect_key($c, array_flip(['WARNING', 'ERROR', 'CRITICAL', 'outcome_HEALTHY', 'outcome_VIOLATION']))];
            }
            $out[(int) $site->id] = $bars;
        }

        return $out;
    }

    /** サービス別の件数（期間内、重大度別） @return array<int,array<string,int>> */
    public function bySite(string $period = '24h', ?CarbonImmutable $now = null): array
    {
        [$from, $to] = self::window($period, $now);
        $out = [];
        foreach (Counter::query()->where('counter_key', CounterService::KEY_SEVERITY_HOURLY)->where('bucket_start', '>=', $from)->where('bucket_start', '<', $to)->get(['site_id', 'counts_json']) as $r) {
            $sid = (int) $r->site_id;
            $out[$sid] ??= ['INFO' => 0, 'WARNING' => 0, 'ERROR' => 0, 'CRITICAL' => 0, 'healthy' => 0, 'judged' => 0];
            foreach (['INFO', 'WARNING', 'ERROR', 'CRITICAL'] as $k) {
                $out[$sid][$k] += (int) ($r->counts_json[$k] ?? 0);
            }
            $out[$sid]['healthy'] += (int) ($r->counts_json['outcome_HEALTHY'] ?? 0);
            $out[$sid]['judged'] += (int) (($r->counts_json['outcome_HEALTHY'] ?? 0) + ($r->counts_json['outcome_WARNING'] ?? 0) + ($r->counts_json['outcome_VIOLATION'] ?? 0) + ($r->counts_json['outcome_UNKNOWN'] ?? 0));
        }

        return $out;
    }
}
