<?php

namespace App\Monitoring\Advisories;

use Carbon\CarbonImmutable;

/** 情報源から取り出した 1 件（保存前の正規化済みデータ）。 */
final class AdvisoryRecord
{
    /**
     * @param  list<string>  $components  一致した製品キー（config monitoring.advisories.products のキー）
     * @param  array<int,array<string,mixed>>  $affected  影響パッケージ・版（{package, range, fixed, release …}）
     */
    public function __construct(
        public readonly string $source,
        public readonly string $externalId,
        public readonly ?string $cveId,
        public readonly string $title,
        public readonly ?string $summary,
        public readonly string $severity,
        public readonly ?float $cvss,
        public readonly ?string $url,
        public readonly array $components,
        public readonly array $affected,
        public readonly ?CarbonImmutable $publishedAt,
        public readonly ?CarbonImmutable $sourceUpdatedAt,
        public readonly bool $kev = false,
        public readonly bool $installedAffected = false,
    ) {}

    /** CVSS 基本値 → 重大度（NVD v3 の区分） */
    public static function severityFromScore(?float $score): string
    {
        if ($score === null) {
            return 'UNKNOWN';
        }

        return match (true) {
            $score >= 9.0 => 'CRITICAL',
            $score >= 7.0 => 'HIGH',
            $score >= 4.0 => 'MEDIUM',
            default => 'LOW',
        };
    }

    public static function normalizeSeverity(?string $s, ?float $score = null): string
    {
        $u = strtoupper(trim((string) $s));
        if (in_array($u, ['CRITICAL', 'HIGH', 'MEDIUM', 'LOW'], true)) {
            return $u;
        }
        if ($u === 'MODERATE') {
            return 'MEDIUM';
        }

        return self::severityFromScore($score);
    }
}
