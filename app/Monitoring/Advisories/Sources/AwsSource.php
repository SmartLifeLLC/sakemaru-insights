<?php

namespace App\Monitoring\Advisories\Sources;

use App\Monitoring\Advisories\AdvisoryRecord;
use App\Monitoring\Advisories\Environment;
use Carbon\CarbonImmutable;

/** AWS Security Bulletins（RSS）。掲載期間が短いので期間内の全件を保存する。 */
class AwsSource extends BaseSource
{
    public function key(): string
    {
        return 'aws';
    }

    public function fetch(CarbonImmutable $since, CarbonImmutable $now, Environment $env): array
    {
        $r = $this->get($this->http(), $this->endpoint(), [], 'AWS Security Bulletins');
        $prev = libxml_use_internal_errors(true);
        $xml = simplexml_load_string((string) $r->body());
        libxml_use_internal_errors($prev);
        if ($xml === false) {
            throw new \RuntimeException('AWS Security Bulletins: RSS を解析できません');
        }
        $out = [];
        foreach ($xml->channel->item ?? [] as $item) {
            $link = trim((string) $item->link);
            $guid = trim((string) ($item->guid ?: $link));
            $title = trim((string) $item->title);
            $published = (string) $item->pubDate !== '' ? CarbonImmutable::parse((string) $item->pubDate)->utc() : null;
            if ($published && $published->lt($since)) {
                continue;
            }
            $desc = trim(strip_tags(html_entity_decode((string) $item->description)));
            // ID は速報番号（…/2026-137-aws/）。guid はハッシュなので表示に向かない。リンクは /rss/ 無しの公開ページへ
            $slug = basename(rtrim($link, '/'));
            $id = preg_match('/^\d{4}-\d+-aws$/i', $slug) ? strtoupper($slug) : ($guid !== '' ? $guid : md5($title));
            $link = str_replace('/security-bulletins/rss/', '/security-bulletins/', $link);
            preg_match('/CVE-\d{4}-\d{4,}/i', $title.' '.$desc, $m);
            $out[] = new AdvisoryRecord(
                'aws', mb_substr($id, 0, 64), isset($m[0]) ? strtoupper($m[0]) : null, $title ?: $id, $desc, 'UNKNOWN', null, $link ?: null,
                array_values(array_unique(array_merge(['aws'], $this->matchProductsInText($title.' '.$desc)))), [],
                $published, $published,
            );
        }

        return $out;
    }
}
