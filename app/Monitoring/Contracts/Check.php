<?php

namespace App\Monitoring\Contracts;

use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\CheckResult;

interface Check
{
    public function definition(): CheckDefinition;

    /**
     * 判定を実行する。業務DBへのアクセスは $context->source() 経由の SELECT のみ。
     * 例外は CheckRunner が FAILED として記録する。判定不能は Outcome::UNKNOWN を返す。
     */
    public function evaluate(CheckContext $context): CheckResult;
}
