<?php

namespace App\Monitoring\Advisories\Sources;

use App\Monitoring\Advisories\AdvisoryRecord;
use App\Monitoring\Advisories\Environment;
use Carbon\CarbonImmutable;

/**
 * NVD CVE API 2.0。製品ごとに CPE 前方一致（virtualMatchString）と最終更新日の範囲で取得する。
 * API キー無しは 5 回/30 秒の公開制限があるため、呼び出し間隔を空ける。
 */
class NvdSource extends BaseSource
{
    public function key(): string
    {
        return 'nvd';
    }

    public function fetch(CarbonImmutable $since, CarbonImmutable $now, Environment $env): array
    {
        $key = $this->secret('nvd_api_key_env');
        $out = [];
        $first = true;
        foreach ($this->products() as $product => $p) {
            if (empty($p['cpe'])) {
                continue;
            }
            if (! $first && $key === null && ! app()->runningUnitTests()) {
                sleep(6);
            }
            $first = false;
            $req = $this->http()->acceptJson();
            if ($key !== null) {
                $req = $req->withHeaders(['apiKey' => $key]);
            }
            // 期間は最大 120 日
            $start = max($since, $now->subDays(119));
            $r = $this->get($req, $this->endpoint(), [
                'virtualMatchString' => $p['cpe'],
                'lastModStartDate' => $start->format('Y-m-d\TH:i:s.000\Z'),
                'lastModEndDate' => $now->format('Y-m-d\TH:i:s.000\Z'),
                'resultsPerPage' => 200,
            ], 'NVD '.$product);
            foreach ((array) $r->json('vulnerabilities', []) as $v) {
                $cve = $v['cve'] ?? [];
                $id = (string) ($cve['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                // 最終更新日で絞ると古い CVE の軽微な更新（CVSS・CPE の修正）が大量に混ざるため、期間内に公開されたものだけ保存する
                $publishedAt = isset($cve['published']) ? CarbonImmutable::parse($cve['published'], 'UTC') : null;
                if ($publishedAt && $publishedAt->lt($since)) {
                    continue;
                }
                $desc = collect((array) ($cve['descriptions'] ?? []))->firstWhere('lang', 'en')['value'] ?? '';
                [$score, $sev] = $this->metrics((array) ($cve['metrics'] ?? []));
                $affected = [];
                foreach ((array) ($cve['configurations'] ?? []) as $conf) {
                    foreach ((array) ($conf['nodes'] ?? []) as $node) {
                        foreach ((array) ($node['cpeMatch'] ?? []) as $m) {
                            if (! empty($m['vulnerable']) && str_starts_with((string) ($m['criteria'] ?? ''), $p['cpe'])) {
                                $affected[] = array_filter(['cpe' => $m['criteria'], 'from' => $m['versionStartIncluding'] ?? null, 'to_excl' => $m['versionEndExcluding'] ?? null, 'to_incl' => $m['versionEndIncluding'] ?? null]);
                            }
                        }
                    }
                }
                $installedAffected = $product === 'php' ? $this->phpAffected($affected, $env->phpVersion()) : false;
                $out[] = new AdvisoryRecord(
                    'nvd', $id, $id, $id.' '.mb_substr($desc, 0, 160), $desc, $sev, $score,
                    'https://nvd.nist.gov/vuln/detail/'.$id, [$product], array_slice($affected, 0, 20),
                    $publishedAt,
                    isset($cve['lastModified']) ? CarbonImmutable::parse($cve['lastModified'], 'UTC') : null,
                    false, $installedAffected,
                );
            }
        }

        return $out;
    }

    /** @return array{0:?float,1:string} */
    private function metrics(array $metrics): array
    {
        foreach (['cvssMetricV40', 'cvssMetricV31', 'cvssMetricV30', 'cvssMetricV2'] as $k) {
            $m = $metrics[$k][0]['cvssData'] ?? null;
            if ($m && isset($m['baseScore'])) {
                $score = (float) $m['baseScore'];

                return [$score, AdvisoryRecord::normalizeSeverity($m['baseSeverity'] ?? $metrics[$k][0]['baseSeverity'] ?? null, $score)];
            }
        }

        return [null, 'UNKNOWN'];
    }

    private function phpAffected(array $affected, string $php): bool
    {
        foreach ($affected as $a) {
            $from = $a['from'] ?? null;
            $toEx = $a['to_excl'] ?? null;
            $toIn = $a['to_incl'] ?? null;
            if ($from === null && $toEx === null && $toIn === null) {
                continue;
            }
            $ok = ($from === null || version_compare($php, $from, '>=')) && ($toEx === null || version_compare($php, $toEx, '<')) && ($toIn === null || version_compare($php, $toIn, '<='));
            if ($ok) {
                return true;
            }
        }

        return false;
    }
}
