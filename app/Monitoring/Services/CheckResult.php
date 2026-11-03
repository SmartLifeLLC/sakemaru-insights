<?php

namespace App\Monitoring\Services;

final class CheckResult
{
    /** @param Observation[] $observations */
    public function __construct(
        public readonly array $observations,
        public readonly ?string $watermarkHash = null,
        public readonly bool $verificationComplete = true,
        public readonly array $checkpoint = [],
    ) {}

    public static function of(Observation ...$observations): self
    {
        return new self($observations);
    }

    public static function single(Observation $o): self
    {
        return new self([$o], $o->watermark, $o->verificationComplete);
    }
}
