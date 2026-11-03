<?php

namespace App\Monitoring\Collectors;

use App\Monitoring\Enums\Severity;
use App\Monitoring\Support\Fingerprint;
use App\Monitoring\Support\Redactor;

/**
 * Laravel 標準ログ形式 "[Y-m-d H:i:s] env.LEVEL: message {context}" のパーサ。
 * 複数行（スタックトレース）は次の先頭行までを一つのレコードにまとめる。形式不明の ERROR らしい行は LOG_PARSE_FAILED。
 */
class LaravelLogParser
{
    private const HEAD = '/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:[+-]\d{2}:?\d{2})?)\]\s+([A-Za-z0-9_-]+)\.([A-Z]+):\s?(.*)$/';

    /**
     * @param  string[]  $lines  完全な行（末尾改行なし）
     * @return array{records: array<int,array<string,mixed>>, carry: string[]} carry は未完了の末尾（次回へ持ち越し）
     */
    public function parse(array $lines, bool $flush = false): array
    {
        $records = [];
        $current = null;
        foreach ($lines as $line) {
            if (preg_match(self::HEAD, $line, $m)) {
                if ($current) {
                    $records[] = $this->finalize($current);
                }
                $current = ['timestamp' => $m[1], 'env' => $m[2], 'level' => $m[3], 'message' => $m[4], 'extra' => []];
            } elseif ($current) {
                $current['extra'][] = $line;
            } elseif (trim($line) !== '') {
                // 前回確定済みレコードのスタック続き（#0 ... / [stacktrace] / "} / 字下げ行）は無視
                if (preg_match('/^(#\d+\s|\[stacktrace\]|"\}|\s|\{"exception")/', $line)) {
                    continue;
                }
                // 先頭行が解析不能
                if (preg_match('/\b(ERROR|CRITICAL|EMERGENCY|ALERT|Exception|Fatal)\b/i', $line)) {
                    $records[] = ['level' => 'ERROR', 'severity' => Severity::ERROR, 'timestamp' => null, 'message' => 'LOG_PARSE_FAILED', 'error_code' => 'LOG_PARSE_FAILED', 'exception' => null, 'sample' => Redactor::truncate(Redactor::text($line) ?? '', 1024), 'fingerprint_material' => 'LOG_PARSE_FAILED'];
                }
            }
        }
        if ($current) {
            if ($flush) {
                $records[] = $this->finalize($current);
                $current = null;
            } else {
                $carry = array_merge(['['.$current['timestamp'].'] '.$current['env'].'.'.$current['level'].': '.$current['message']], $current['extra']);

                return ['records' => $records, 'carry' => $carry];
            }
        }

        return ['records' => $records, 'carry' => []];
    }

    /** @param array<string,mixed> $r */
    private function finalize(array $r): array
    {
        $level = strtoupper($r['level']);
        $severity = Severity::fromLogLevel($level);
        $message = $r['message'];
        $exception = null;
        // メッセージ末尾の JSON コンテキストから exception を抽出
        if (preg_match('/\{"exception":"\[object\] \(([A-Za-z0-9_\\\\]+)\(code: [^)]*\): (.*?) at ([^:]+):(\d+)\)/su', $message.' '.implode(' ', $r['extra']), $em)) {
            $exception = ['class' => $em[1], 'message' => $em[2], 'file' => $em[3]];
        } elseif (preg_match('/^([A-Za-z0-9_\\\\]+Exception|[A-Za-z0-9_\\\\]+Error)[:\s]/', $message, $em)) {
            $exception = ['class' => $em[1], 'message' => $message, 'file' => null];
        }
        $plainMessage = preg_replace('/\s*\{"exception":.*$/su', '', $message);
        $material = ($exception['class'] ?? 'LOG').'|'.Fingerprint::normalizeExceptionMessage($exception['message'] ?? $plainMessage).'|'.(! empty($exception['file']) ? preg_replace('/^.*\/(app|vendor)\//', '$1/', $exception['file']) : '');
        $sample = implode("\n", array_merge([$plainMessage], array_slice($r['extra'], 0, 15)));

        return [
            'level' => $level,
            'severity' => $severity,
            'timestamp' => $r['timestamp'],
            'message' => mb_substr(Redactor::text($plainMessage) ?? '', 0, 500),
            'error_code' => $exception ? mb_substr(basename(str_replace('\\', '/', $exception['class'])), 0, 64) : 'LOG_'.$level,
            'exception' => $exception ? ['class' => $exception['class'], 'file' => $exception['file']] : null,
            'sample' => Redactor::truncate(Redactor::text($sample) ?? '', (int) config('monitoring.ingest.sample_max_bytes', 8192)),
            'fingerprint_material' => $material,
        ];
    }
}
