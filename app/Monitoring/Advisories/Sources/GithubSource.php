<?php

namespace App\Monitoring\Advisories\Sources;

use App\Monitoring\Advisories\AdvisoryRecord;
use App\Monitoring\Advisories\Environment;
use App\Monitoring\Advisories\VersionRange;
use Carbon\CarbonImmutable;

/**
 * GitHub Advisory Database（Composer エコシステム）。期間内に更新された全件を取り、composer.lock の導入パッケージと照合する。
 * 導入していないパッケージの情報は保存しない。
 */
class GithubSource extends BaseSource
{
    public function key(): string
    {
        return 'github';
    }

    public function fetch(CarbonImmutable $since, CarbonImmutable $now, Environment $env): array
    {
        $installed = $env->composerPackages();
        if ($installed === []) {
            return [];
        }
        $token = $this->secret('github_token_env');
        $out = [];
        $url = $this->endpoint();
        $query = ['ecosystem' => 'composer', 'updated' => '>='.$since->format('Y-m-d'), 'per_page' => 100, 'sort' => 'updated', 'direction' => 'desc'];
        for ($page = 0; $page < 10 && $url; $page++) {
            $req = $this->http()->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28']);
            if ($token !== null) {
                $req = $req->withToken($token);
            }
            $r = $this->get($req, $url, $page === 0 ? $query : [], 'GitHub Advisory');
            foreach ((array) $r->json() as $a) {
                $affected = [];
                $components = [];
                $installedAffected = false;
                foreach ((array) ($a['vulnerabilities'] ?? []) as $v) {
                    $pkg = strtolower((string) ($v['package']['name'] ?? ''));
                    if ($pkg === '' || ! isset($installed[$pkg])) {
                        continue;
                    }
                    $range = (string) ($v['vulnerable_version_range'] ?? '');
                    $hit = VersionRange::contains($range, $installed[$pkg]);
                    $affected[] = ['package' => $pkg, 'range' => $range, 'fixed' => $v['first_patched_version'] ?? null, 'installed' => $installed[$pkg], 'installed_affected' => $hit];
                    $components = array_merge($components, $env->productsForComposer($pkg));
                    $installedAffected = $installedAffected || $hit === true;
                }
                if ($affected === []) {
                    continue; // 導入していないパッケージ
                }
                $score = isset($a['cvss']['score']) && $a['cvss']['score'] !== null ? (float) $a['cvss']['score'] : null;
                $out[] = new AdvisoryRecord(
                    'github', (string) $a['ghsa_id'], $a['cve_id'] ?? null, (string) ($a['summary'] ?? $a['ghsa_id']), $a['description'] ?? null,
                    AdvisoryRecord::normalizeSeverity($a['severity'] ?? null, $score), $score, $a['html_url'] ?? null,
                    array_values(array_unique($components)), $affected,
                    isset($a['published_at']) ? CarbonImmutable::parse($a['published_at'], 'UTC') : null,
                    isset($a['updated_at']) ? CarbonImmutable::parse($a['updated_at'], 'UTC') : null,
                    false, $installedAffected,
                );
            }
            $url = $this->nextLink((string) $r->header('Link'));
        }

        return $out;
    }

    private function nextLink(string $link): ?string
    {
        return preg_match('/<([^>]+)>;\s*rel="next"/', $link, $m) ? $m[1] : null;
    }
}
