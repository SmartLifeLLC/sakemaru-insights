<?php

namespace App\Monitoring\Services;

use Carbon\CarbonImmutable;

final class CheckContext
{
    /**
     * @param  array<string,mixed>  $config  許可された設定（defaultConfig + DB の config_json）
     * @param  array<string,mixed>  $scope  再確認予約時の固定 scope
     */
    public function __construct(
        private readonly SourceReader $source,
        public readonly CarbonImmutable $nowUtc,
        public readonly array $config,
        public readonly array $scope = [],
        public readonly string $triggerType = 'SCHEDULE',
        public readonly array $previousCheckpoint = [],
    ) {}

    public function source(): SourceReader
    {
        return $this->source;
    }

    public function nowLocal(): CarbonImmutable
    {
        return $this->nowUtc->setTimezone(config('monitoring.deployment.timezone', 'Asia/Tokyo'));
    }

    /** 対象営業日（既定は前日 JST）。scope で固定された場合はそれを優先。 */
    public function targetBusinessDate(int $daysAgo = 1): string
    {
        if (! empty($this->scope['business_date'])) {
            return (string) $this->scope['business_date'];
        }

        return $this->nowLocal()->subDays($daysAgo)->toDateString();
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }
}
