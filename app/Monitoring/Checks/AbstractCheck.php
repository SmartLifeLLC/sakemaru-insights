<?php

namespace App\Monitoring\Checks;

use App\Monitoring\Contracts\Check;
use App\Monitoring\Contracts\CheckDefinition;
use Carbon\CarbonImmutable;

abstract class AbstractCheck implements Check
{
    abstract public function definition(): CheckDefinition;

    /** JST の "HH:MM" と対象日から UTC の締切時刻を返す。 */
    protected function deadlineUtc(string $date, string $hhmm, int $graceMinutes = 0): CarbonImmutable
    {
        return CarbonImmutable::parse($date.' '.$hhmm, 'Asia/Tokyo')->addMinutes($graceMinutes)->utc();
    }

    /** @param array<int,object> $rows */
    protected function sample(array $rows, int $max = 20): array
    {
        return array_map(fn ($r) => (array) $r, array_slice($rows, 0, $max));
    }
}
