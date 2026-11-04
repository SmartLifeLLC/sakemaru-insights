<?php

namespace App\Monitoring\Advisories;

use App\Models\Monitoring\Advisory;
use App\Models\Monitoring\AdvisoryFetch;
use App\Monitoring\Advisories\Sources\AwsSource;
use App\Monitoring\Advisories\Sources\GithubSource;
use App\Monitoring\Advisories\Sources\JvnSource;
use App\Monitoring\Advisories\Sources\KevSource;
use App\Monitoring\Advisories\Sources\NvdSource;
use App\Monitoring\Advisories\Sources\UbuntuSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * 情報源を順に取得し、ins_monitor_advisories に upsert する。
 * - 新規行は status=NEW。既存行は内容（重大度・影響・更新日時）だけ更新し、状態は変えない
 * - KEV の CVE は他情報源の既存行にも kev=true を付ける
 * - 新規 CVE には EPSS（悪用確率）を付ける
 * 失敗した情報源は記録して次へ進む（他の情報源を止めない）。
 */
class AdvisoryFetcher
{
    public const SOURCES = ['nvd' => NvdSource::class, 'github' => GithubSource::class, 'ubuntu' => UbuntuSource::class, 'kev' => KevSource::class, 'aws' => AwsSource::class, 'jvn' => JvnSource::class];

    public function __construct(private readonly Environment $env) {}

    /**
     * @param  list<string>|null  $only  情報源キーの絞り込み
     * @return array<string,array{status:string,fetched:int,new:int,error:?string}>
     */
    public function run(CarbonImmutable $now, ?array $only = null, ?CarbonImmutable $since = null): array
    {
        $enabled = (array) config('monitoring.advisories.sources');
        $result = [];
        $kevCves = [];
        $newCves = [];
        foreach (self::SOURCES as $key => $class) {
            if (! in_array($key, $enabled, true) || ($only !== null && ! in_array($key, $only, true))) {
                continue;
            }
            $src = app($class);
            $from = $since ?? $this->sinceFor($key, $now);
            $log = AdvisoryFetch::query()->create(['source' => $key, 'started_at' => $now, 'status' => 'RUNNING']);
            try {
                $records = $src->fetch($from, $now, $this->env);
                $new = 0;
                foreach ($records as $rec) {
                    if ($this->upsert($rec, $now)) {
                        $new++;
                        if ($rec->cveId) {
                            $newCves[] = $rec->cveId;
                        }
                    }
                }
                if ($src instanceof KevSource) {
                    $kevCves = $src->allCves;
                }
                $log->forceFill(['finished_at' => CarbonImmutable::now('UTC'), 'status' => 'OK', 'fetched_count' => count($records), 'new_count' => $new])->save();
                $result[$key] = ['status' => 'OK', 'fetched' => count($records), 'new' => $new, 'error' => null];
            } catch (\Throwable $e) {
                $msg = mb_substr(get_class($e).': '.$e->getMessage(), 0, 1000);
                $log->forceFill(['finished_at' => CarbonImmutable::now('UTC'), 'status' => 'FAILED', 'error' => $msg])->save();
                $result[$key] = ['status' => 'FAILED', 'fetched' => 0, 'new' => 0, 'error' => $msg];
            }
        }
        if ($kevCves !== []) {
            Advisory::query()->whereIn('cve_id', array_slice($kevCves, 0, 5000))->where('kev', false)->update(['kev' => true]);
        }
        $this->enrichEpss(array_values(array_unique($newCves)));
        $this->pruneLogs();

        return $result;
    }

    /** 初回は既定の遡り日数、以後は前回成功の開始時刻から 1 日の重なりを持って取る */
    public function sinceFor(string $source, CarbonImmutable $now): CarbonImmutable
    {
        $last = AdvisoryFetch::query()->where('source', $source)->where('status', 'OK')->orderByDesc('id')->value('started_at');
        $lookback = $now->subDays(max(1, (int) config('monitoring.advisories.initial_lookback_days', 30)));

        return $last ? max(CarbonImmutable::instance($last)->subDay(), $lookback) : $lookback;
    }

    /** @return bool 新規に作成したか */
    private function upsert(AdvisoryRecord $r, CarbonImmutable $now): bool
    {
        $row = Advisory::query()->where('source', $r->source)->where('external_id', $r->externalId)->first();
        $data = [
            'cve_id' => $r->cveId, 'title' => mb_substr($r->title, 0, 500), 'summary' => $r->summary !== null ? mb_substr($r->summary, 0, 20000) : null,
            'severity' => $r->severity, 'cvss' => $r->cvss, 'url' => $r->url !== null ? mb_substr($r->url, 0, 500) : null,
            'components_json' => $r->components, 'affected_json' => $r->affected, 'installed_affected' => $r->installedAffected,
            'published_at' => $r->publishedAt, 'source_updated_at' => $r->sourceUpdatedAt, 'last_seen_at' => $now,
        ];
        if ($row) {
            if ($r->kev) {
                $data['kev'] = true;
            }
            // 同じ ID が複数の製品に該当することがある → 製品は和集合、導入版の影響はどちらかが真なら真
            $data['components_json'] = array_values(array_unique(array_merge((array) $row->components_json, $r->components)));
            $data['installed_affected'] = $row->installed_affected || $r->installedAffected;
            $row->forceFill($data)->save();

            return false;
        }
        Advisory::query()->create($data + ['source' => $r->source, 'external_id' => $r->externalId, 'kev' => $r->kev, 'first_seen_at' => $now, 'status' => 'NEW']);

        return true;
    }

    /** @param list<string> $cves */
    private function enrichEpss(array $cves): void
    {
        if ($cves === []) {
            return;
        }
        $endpoint = (string) config('monitoring.advisories.endpoints.epss');
        foreach (array_chunk($cves, 100) as $chunk) {
            try {
                $r = Http::timeout((int) config('monitoring.advisories.http_timeout_seconds', 20))->withUserAgent((string) config('monitoring.advisories.user_agent'))->acceptJson()->get($endpoint, ['cve' => implode(',', $chunk)]);
                if (! $r->successful()) {
                    return;
                }
                foreach ((array) $r->json('data', []) as $d) {
                    if (isset($d['cve'], $d['epss'])) {
                        Advisory::query()->where('cve_id', $d['cve'])->update(['epss' => round((float) $d['epss'], 5)]);
                    }
                }
            } catch (\Throwable) {
                return; // 付加情報なので失敗しても取得全体は成功扱い
            }
        }
    }

    private function pruneLogs(): void
    {
        $keep = (int) config('monitoring.advisories.fetch_log_keep', 300);
        $cut = AdvisoryFetch::query()->orderByDesc('id')->skip($keep)->value('id');
        if ($cut) {
            AdvisoryFetch::query()->where('id', '<=', $cut)->delete();
        }
    }
}
