<?php

namespace App\Monitoring\Advisories;

use Carbon\CarbonImmutable;

/** 情報源 1 つ。HTTP は Laravel の Http ファサードだけを使う（テストで fake できる）。 */
interface AdvisorySource
{
    public function key(): string;

    /**
     * @return list<AdvisoryRecord>
     *
     * @throws \RuntimeException 取得失敗（記録して次の情報源へ進む）
     */
    public function fetch(CarbonImmutable $since, CarbonImmutable $now, Environment $env): array;
}
