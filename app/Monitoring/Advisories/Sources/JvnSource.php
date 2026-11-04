<?php

namespace App\Monitoring\Advisories\Sources;

use App\Monitoring\Advisories\AdvisoryRecord;
use App\Monitoring\Advisories\Environment;
use Carbon\CarbonImmutable;

/**
 * JVN iPedia（MyJVN API getVulnOverviewList）。製品名キーワードごとに公開日の範囲で取得する（日本語の概要）。
 * 応答は JVNRSS（RDF/XML）。
 */
class JvnSource extends BaseSource
{
    public function key(): string
    {
        return 'jvn';
    }

    public function fetch(CarbonImmutable $since, CarbonImmutable $now, Environment $env): array
    {
        $out = [];
        $s = $since->setTimezone('Asia/Tokyo');
        $e = $now->setTimezone('Asia/Tokyo');
        foreach ($this->products() as $product => $p) {
            if (empty($p['jvn'])) {
                continue;
            }
            $r = $this->get($this->http(), $this->endpoint(), [
                'method' => 'getVulnOverviewList', 'feed' => 'hnd', 'keyword' => $p['jvn'], 'rangeDatePublished' => 'n', 'rangeDatePublic' => 'n',
                'datePublishedStartY' => $s->format('Y'), 'datePublishedStartM' => $s->format('n'), 'datePublishedStartD' => $s->format('j'),
                'datePublishedEndY' => $e->format('Y'), 'datePublishedEndM' => $e->format('n'), 'datePublishedEndD' => $e->format('j'),
                'maxCountItem' => 50, 'lang' => 'ja',
            ], 'JVN '.$product);
            if (str_starts_with(ltrim((string) $r->body()), '<!DOCTYPE html') || str_contains((string) $r->body(), 'サービス停止中')) {
                throw new \RuntimeException('JVN iPedia / MyJVN API がサービス停止中（HTML 応答）。再開後に自動で取得します。');
            }
            $prev = libxml_use_internal_errors(true);
            $xml = simplexml_load_string((string) $r->body());
            libxml_use_internal_errors($prev);
            if ($xml === false) {
                throw new \RuntimeException('JVN '.$product.': 応答を解析できません');
            }
            $xml->registerXPathNamespace('rss', 'http://purl.org/rss/1.0/');
            $xml->registerXPathNamespace('sec', 'http://jvn.jp/rss/mod_sec/3.0/');
            $xml->registerXPathNamespace('dc', 'http://purl.org/dc/elements/1.1/');
            $xml->registerXPathNamespace('dcterms', 'http://purl.org/dc/terms/');
            foreach ($xml->xpath('//rss:item') ?: [] as $item) {
                $item->registerXPathNamespace('rss', 'http://purl.org/rss/1.0/');
                $item->registerXPathNamespace('sec', 'http://jvn.jp/rss/mod_sec/3.0/');
                $item->registerXPathNamespace('dc', 'http://purl.org/dc/elements/1.1/');
                $item->registerXPathNamespace('dcterms', 'http://purl.org/dc/terms/');
                $id = trim((string) ($item->xpath('sec:identifier')[0] ?? ''));
                if ($id === '') {
                    continue;
                }
                $title = trim((string) ($item->xpath('rss:title')[0] ?? $id));
                $desc = trim((string) ($item->xpath('rss:description')[0] ?? ''));
                $link = trim((string) ($item->xpath('rss:link')[0] ?? ''));
                $cve = null;
                foreach ($item->xpath('sec:references') ?: [] as $ref) {
                    $a = $ref->attributes();
                    if (isset($a['source']) && (string) $a['source'] === 'CVE') {
                        $cve = trim((string) ($a['id'] ?? $ref));
                        break;
                    }
                }
                $score = null;
                foreach ($item->xpath('sec:cvss') ?: [] as $c) {
                    $a = $c->attributes();
                    if (isset($a['score']) && is_numeric((string) $a['score'])) {
                        $score = max($score ?? 0, (float) $a['score']);
                    }
                }
                $issued = (string) ($item->xpath('dcterms:issued')[0] ?? $item->xpath('dc:date')[0] ?? '');
                $modified = (string) ($item->xpath('dcterms:modified')[0] ?? '');
                $out[] = new AdvisoryRecord(
                    'jvn', $id, $cve ? strtoupper($cve) : null, $title, $desc, AdvisoryRecord::severityFromScore($score), $score, $link ?: null,
                    [$product], [], $issued !== '' ? CarbonImmutable::parse($issued)->utc() : null, $modified !== '' ? CarbonImmutable::parse($modified)->utc() : null,
                );
            }
        }

        return $out;
    }
}
