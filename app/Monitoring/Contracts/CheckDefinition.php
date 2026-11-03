<?php

namespace App\Monitoring\Contracts;

/**
 * コードに登録される監視項目の定義（仕様書 4.1 CheckRegistry / 第8章）。
 * 画面で変更できるのは enabled / grace / 許可された config のみ。
 */
final class CheckDefinition
{
    /**
     * @param  array<string,mixed>  $defaultConfig  許可されたパラメータの初期値
     * @param  string[]  $editableConfigKeys  画面から変更可能なキー
     */
    public function __construct(
        public readonly string $key,
        public readonly string $applicationCode,
        public readonly string $displayName,
        public readonly string $description,
        public readonly int $version,
        public readonly int $intervalSeconds,
        public readonly int $graceSeconds,
        public readonly ?string $runbookKey,
        public readonly bool $heavy = false,
        public readonly bool $includesBusinessDateInFingerprint = true,
        public readonly int $recoveryHealthyStreak = 2,
        public readonly bool $implemented = true,
        public readonly array $defaultConfig = [],
        public readonly array $editableConfigKeys = [],
        public readonly ?string $implementationNote = null,
    ) {}
}
