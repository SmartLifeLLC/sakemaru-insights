<?php

namespace App\Monitoring\Advisories\Sources;

use App\Monitoring\Advisories\AdvisoryRecord;
use App\Monitoring\Advisories\Environment;
use Carbon\CarbonImmutable;

/**
 * Ubuntu Security Notices（ubuntu.com/security/notices.json）。導入リリースの通知を新しい順に取り、
 * 修正パッケージ名を導入 deb と照合する。リリース名が取れない環境（Ubuntu 以外）は対象製品の通知だけ保存する。
 */
class UbuntuSource extends BaseSource
{
    public function key(): string
    {
        return 'ubuntu';
    }

    public function fetch(CarbonImmutable $since, CarbonImmutable $now, Environment $env): array
    {
        $codename = $env->ubuntuCodename();
        $debs = $env->debPackages();
        $out = [];
        $offset = 0;
        $stop = false;
        for ($page = 0; $page < 10 && ! $stop; $page++) {
            $r = $this->get($this->http()->acceptJson(), $this->endpoint(), array_filter(['release' => $codename, 'limit' => 20, 'offset' => $offset, 'order' => 'newest']), 'Ubuntu USN');
            $notices = (array) $r->json('notices', []);
            if ($notices === []) {
                break;
            }
            foreach ($notices as $n) {
                $published = isset($n['published']) ? CarbonImmutable::parse($n['published'], 'UTC') : null;
                if ($published && $published->lt($since)) {
                    $stop = true;
                    break;
                }
                $affected = [];
                $components = [];
                $installedAffected = false;
                $packages = (array) ($n['release_packages'] ?? []);
                $list = $codename && isset($packages[$codename]) ? (array) $packages[$codename] : array_merge(...array_values(array_map(fn ($v) => (array) $v, $packages)) ?: [[]]);
                foreach ($list as $pkg) {
                    $name = (string) ($pkg['name'] ?? '');
                    if ($name === '') {
                        continue;
                    }
                    $keys = $env->productsForDeb($name);
                    $installed = $debs[$name] ?? null;
                    if ($installed === null && $keys === []) {
                        continue;
                    }
                    $components = array_merge($components, $keys ?: ['ubuntu']);
                    $affected[] = array_filter(['package' => $name, 'fixed' => $pkg['version'] ?? null, 'installed' => $installed]);
                    if ($installed !== null && isset($pkg['version']) && $this->debLess($installed, (string) $pkg['version'])) {
                        $installedAffected = true;
                    }
                }
                if ($affected === []) {
                    continue;
                }
                $cves = array_values(array_filter(array_map(fn ($c) => is_array($c) ? ($c['id'] ?? null) : $c, (array) ($n['cves_ids'] ?? $n['cves'] ?? []))));
                $out[] = new AdvisoryRecord(
                    'ubuntu', (string) $n['id'], $cves[0] ?? null, (string) ($n['title'] ?? $n['id']), (string) ($n['summary'] ?? $n['description'] ?? ''),
                    'UNKNOWN', null, 'https://ubuntu.com/security/notices/'.$n['id'],
                    array_values(array_unique($components)), array_merge(array_slice($affected, 0, 30), array_map(fn ($c) => ['cve' => $c], array_slice($cves, 0, 20))),
                    $published, $published, false, $installedAffected,
                );
            }
            $offset += 20;
        }

        return $out;
    }

    /** 導入版 < 修正版 か（dpkg --compare-versions があればそれを、無ければ version_compare） */
    private function debLess(string $installed, string $fixed): bool
    {
        if (is_executable('/usr/bin/dpkg') && ! app()->runningUnitTests()) {
            exec('/usr/bin/dpkg --compare-versions '.escapeshellarg($installed).' lt '.escapeshellarg($fixed), $o, $code);

            return $code === 0;
        }

        // 簡易比較: 数字の並び（2.4.58-1ubuntu8.5 → 2,4,58,1,8,5）を左から数値で比べる
        $seg = fn (string $v) => array_map('intval', preg_split('/[^0-9]+/', preg_replace('/^\d+:/', '', $v), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $a = $seg($installed);
        $b = $seg($fixed);
        for ($i = 0, $n = max(count($a), count($b)); $i < $n; $i++) {
            $x = $a[$i] ?? 0;
            $y = $b[$i] ?? 0;
            if ($x !== $y) {
                return $x < $y;
            }
        }

        return false;
    }
}
