<?php

namespace App\Monitoring\Advisories\Sources;

use App\Monitoring\Advisories\AdvisoryRecord;
use App\Monitoring\Advisories\Environment;
use Carbon\CarbonImmutable;

/**
 * CISA KEV（悪用が確認された脆弱性）。全件 JSON を取り、期間内に追加され対象製品に関係するものを保存する。
 * 既存の他情報源の行は、同じ CVE が KEV にあれば AdvisoryFetcher が kev=true を付ける。
 */
class KevSource extends BaseSource
{
    /** @var list<string> 直近の取得で得た全 CVE（既存行の kev 付与用） */
    public array $allCves = [];

    public function key(): string
    {
        return 'kev';
    }

    public function fetch(CarbonImmutable $since, CarbonImmutable $now, Environment $env): array
    {
        $r = $this->get($this->http()->acceptJson(), $this->endpoint(), [], 'CISA KEV');
        $out = [];
        $this->allCves = [];
        foreach ((array) $r->json('vulnerabilities', []) as $v) {
            $cve = (string) ($v['cveID'] ?? '');
            if ($cve === '') {
                continue;
            }
            $this->allCves[] = $cve;
            $added = isset($v['dateAdded']) ? CarbonImmutable::parse($v['dateAdded'], 'UTC') : null;
            if ($added && $added->lt($since->startOfDay())) {
                continue;
            }
            $components = $this->matchProductsInText(($v['vendorProject'] ?? '').' '.($v['product'] ?? '').' '.($v['vulnerabilityName'] ?? ''));
            if ($components === []) {
                continue;
            }
            $out[] = new AdvisoryRecord(
                'kev', $cve, $cve, (string) ($v['vulnerabilityName'] ?? $cve), trim(($v['shortDescription'] ?? '').' 推奨対応: '.($v['requiredAction'] ?? '')),
                'CRITICAL', null, 'https://www.cisa.gov/known-exploited-vulnerabilities-catalog?search_api_fulltext='.$cve,
                $components, [array_filter(['vendor' => $v['vendorProject'] ?? null, 'product' => $v['product'] ?? null, 'due' => $v['dueDate'] ?? null, 'ransomware' => $v['knownRansomwareCampaignUse'] ?? null])],
                $added, $added, true, false,
            );
        }

        return $out;
    }
}
