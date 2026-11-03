<?php

namespace App\Monitoring\Services;

use App\Monitoring\Enums\Outcome;
use App\Monitoring\Enums\Severity;

/** チェック1回が返す観測単位。1観測＝1ログ行。VIOLATION/ERROR はインシデントへ昇格する。 */
final class Observation
{
    /**
     * @param  array<string,mixed>  $scope  正規化された対象（店舗群・企画など）。指紋に使う。
     * @param  array<string,mixed>  $metrics
     * @param  array<string,mixed>  $evidence
     */
    public function __construct(
        public readonly Outcome $outcome,
        public readonly Severity $severity,
        public readonly string $summary,
        public readonly array $scope = [],
        public readonly ?string $businessDate = null,
        public readonly array $metrics = [],
        public readonly array $evidence = [],
        public readonly ?string $errorCode = null,
        public readonly ?string $watermark = null,
        public readonly bool $verificationComplete = true,
        public readonly ?string $applicationCode = null,
    ) {}

    /** 観測の帰属サービスを変える（HTTP プローブなど INSIGHTS から他サービスを観測する場合）。指紋・通知見出しはこのサービスになる。 */
    public function forSite(string $applicationCode): self
    {
        return new self($this->outcome, $this->severity, $this->summary, $this->scope, $this->businessDate, $this->metrics, $this->evidence, $this->errorCode, $this->watermark, $this->verificationComplete, strtoupper($applicationCode));
    }

    public static function healthy(string $summary, array $scope = [], ?string $businessDate = null, array $metrics = [], ?string $watermark = null): self
    {
        return new self(Outcome::HEALTHY, Severity::INFO, $summary, $scope, $businessDate, $metrics, [], null, $watermark, true);
    }

    public static function warning(string $summary, array $scope = [], ?string $businessDate = null, array $metrics = [], array $evidence = [], ?string $errorCode = null, ?string $watermark = null): self
    {
        return new self(Outcome::WARNING, Severity::WARNING, $summary, $scope, $businessDate, $metrics, $evidence, $errorCode, $watermark, true);
    }

    public static function violation(string $summary, array $scope = [], ?string $businessDate = null, array $metrics = [], array $evidence = [], ?string $errorCode = null, Severity $severity = Severity::ERROR, ?string $watermark = null): self
    {
        return new self(Outcome::VIOLATION, $severity, $summary, $scope, $businessDate, $metrics, $evidence, $errorCode, $watermark, true);
    }

    public static function unknown(string $summary, array $scope = [], ?string $businessDate = null, array $evidence = [], ?string $errorCode = null): self
    {
        return new self(Outcome::UNKNOWN, Severity::WARNING, $summary, $scope, $businessDate, [], $evidence, $errorCode, null, false);
    }

    public static function notDue(string $summary, array $scope = [], ?string $businessDate = null): self
    {
        return new self(Outcome::NOT_DUE, Severity::INFO, $summary, $scope, $businessDate, [], [], null, null, false);
    }
}
