<?php

namespace App\Monitoring\Support;

/**
 * インシデント指紋（仕様書 10.1）。
 * application + check_key または error_code + 正規化 scope + 対象営業日（チェック定義で含める場合）。
 * ノード固有エラーだけ node_code を含める。行番号・リクエストID・時刻・金額・顧客名は含めない。
 */
final class Fingerprint
{
    public static function compute(
        string $applicationCode,
        ?string $checkKey,
        ?string $errorCode,
        array $scope,
        ?string $businessDate,
        ?string $nodeCode = null,
    ): string {
        $parts = [
            'app' => strtoupper($applicationCode),
            'key' => $checkKey ?: ('ERR:'.($errorCode ?: 'UNSPECIFIED')),
            'scope' => self::normalizeScope($scope),
            'date' => $businessDate ?: null,
            'node' => $nodeCode ?: null,
        ];

        return CanonicalJson::hash($parts);
    }

    /** @return array<string,mixed> */
    public static function normalizeScope(array $scope): array
    {
        $out = [];
        foreach ($scope as $k => $v) {
            if ($v === null || $v === '' || $v === []) {
                continue;
            }
            if (is_array($v)) {
                $vals = array_values(array_unique(array_map(fn ($x) => is_scalar($x) ? (string) $x : CanonicalJson::encode($x), $v)));
                sort($vals, SORT_STRING);
                $out[(string) $k] = $vals;
            } elseif (is_bool($v)) {
                $out[(string) $k] = $v;
            } else {
                $out[(string) $k] = trim((string) $v);
            }
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    /**
     * アプリ例外用の安定した指紋素材。例外クラスと、ファイルパス（行番号除去）・メッセージの数値/ID/日時を伏字化したもの。
     */
    public static function normalizeExceptionMessage(string $message): string
    {
        $m = $message;
        $m = preg_replace('/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i', '<uuid>', $m);
        $m = preg_replace('/\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?(\.\d+)?/', '<datetime>', $m);
        $m = preg_replace('/\d{4}-\d{2}-\d{2}/', '<date>', $m);
        $m = preg_replace('/\b[0-9a-f]{32,}\b/i', '<hash>', $m);
        $m = preg_replace('/\b(?=[0-9a-f]*\d)[0-9a-f]{4,}\b/i', '<n>', $m);
        $m = preg_replace('/\d+/', '<n>', $m);
        $m = preg_replace('/\s+/', ' ', $m);

        return mb_substr(trim($m), 0, 300);
    }
}
