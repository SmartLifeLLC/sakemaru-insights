<?php

namespace App\Monitoring\Collectors;

use App\Monitoring\Enums\Severity;
use App\Monitoring\Enums\SourceKind;
use App\Monitoring\Support\CanonicalJson;
use Carbon\CarbonImmutable;

/**
 * 自ノードの許可済みログファイルを増分読取りし、退避ファイルへ書く（仕様書 6.3）。
 * - 許可パスは config/monitoring.php の collectors に固定。UI から指定させない。
 * - inode・offset・世代を state に永続化し、rename / copytruncate / 再起動を扱う。
 * - event_id はファイル世代・開始 offset・内容 hash から決定的に生成する。
 * - 同じ指紋の大量発生は 60 秒単位で集約する。
 */
class FileLogCollector
{
    public function __construct(
        private readonly array $collectorConfig,
        private readonly LaravelLogParser $parser,
        private readonly Spool $spool,
        private readonly string $stateDir,
    ) {}

    /** @return array{events:int,bytes:int,files:array<string,mixed>} */
    public function collect(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $state = $this->loadState();
        $events = [];
        $totalBytes = 0;
        $maxBytes = (int) config('monitoring.collector.read_bytes_per_second', 262144) * 30; // 1回の収集で最大30秒分
        $report = [];

        foreach ($this->expandLogFiles((array) ($this->collectorConfig['log_files'] ?? [])) as $path) {
            $fileState = $state['files'][$path] ?? ['inode' => null, 'generation' => 0, 'offset' => 0, 'carry' => [], 'initialized' => false];
            if (! is_file($path) || ! is_readable($path)) {
                $report[$path] = 'unreadable';

                continue;
            }
            $stat = stat($path);
            $inode = (string) ($stat['ino'] ?? '');
            $size = (int) $stat['size'];
            // rotate (rename) / copytruncate の検知: inode 変化・縮小・先頭内容の変化 → 世代更新を先に永続化
            $headChanged = false;
            if (! empty($fileState['head_hash']) && $size >= (int) $fileState['head_len']) {
                $headChanged = $this->headHash($path, (int) $fileState['head_len']) !== $fileState['head_hash'];
            }
            if ($fileState['inode'] !== null && ($fileState['inode'] !== $inode || $size < $fileState['offset'] || $headChanged)) {
                $fileState['generation']++;
                $fileState['offset'] = 0;
                $fileState['carry'] = [];
                $fileState['inode'] = $inode;
                $fileState['head_len'] = min($size, 1024);
                $fileState['head_hash'] = $size > 0 ? $this->headHash($path, $fileState['head_len']) : null;
                $state['files'][$path] = $fileState;
                $this->saveState($state);
            }
            if ($fileState['inode'] === null) {
                $fileState['inode'] = $inode;
            }
            if (empty($fileState['head_hash']) && $size > 0) {
                $fileState['head_len'] = min($size, 1024);
                $fileState['head_hash'] = $this->headHash($path, $fileState['head_len']);
            }
            if (! $fileState['initialized']) {
                // 初回導入: 直近24時間・最大50MiB だけを対象にし、それ以前は読まない
                $initialMax = (int) config('monitoring.collector.initial_max_bytes', 52428800);
                $fileState['offset'] = max(0, $size - $initialMax);
                $fileState['initialized'] = true;
                $fileState['initial_truncated'] = $fileState['offset'] > 0;
            }
            $fh = fopen($path, 'r');
            fseek($fh, $fileState['offset']);
            $chunk = '';
            $readBytes = 0;
            while (! feof($fh) && $readBytes < $maxBytes - $totalBytes) {
                $buf = fread($fh, 65536);
                if ($buf === false || $buf === '') {
                    break;
                }
                $chunk .= $buf;
                $readBytes += strlen($buf);
            }
            fclose($fh);
            if ($chunk === '' && ! $fileState['carry']) {
                $report[$path] = 'no_new_data';
                $state['files'][$path] = $fileState;

                continue;
            }
            // 途中行は次回へ持ち越す（末尾が改行で終わらない場合）
            $lastNl = strrpos($chunk, "\n");
            if ($lastNl === false) {
                $complete = '';
                $partial = $chunk;
            } else {
                $complete = substr($chunk, 0, $lastNl + 1);
                $partial = substr($chunk, $lastNl + 1);
            }
            $startOffset = $fileState['offset'];
            $lines = array_merge($fileState['carry'], $complete === '' ? [] : explode("\n", rtrim($complete, "\n")));
            // 末尾が改行で終わっていれば末尾レコードも確定する（稀なエラーを次の行まで待たせない）。
            // 行の途中（改行なし）だけを持ち越す。後続のスタック行が次回に現れても、イベントは重複しない（決定的 event_id）。
            $parsed = $this->parser->parse($lines, flush: true);
            $fileState['carry'] = $parsed['carry'];
            $fileState['offset'] = $startOffset + strlen($complete);
            // 行途中の partial は offset を進めず次回読み直す
            $totalBytes += strlen($complete);

            $events = array_merge($events, $this->toEvents($parsed['records'], $path, $fileState['generation'], $startOffset, $now));
            $report[$path] = ['read' => strlen($complete), 'records' => count($parsed['records']), 'carry' => count($parsed['carry']), 'initial_truncated' => $fileState['initial_truncated'] ?? false];
            $state['files'][$path] = $fileState;
            if (strlen($partial) > 0) {
                // partial は読み直し対象（offset を進めない）
            }
        }

        if ($events) {
            $this->spool->append($events);
        }
        $this->saveState($state);

        return ['events' => count($events), 'bytes' => $totalBytes, 'files' => $report];
    }

    /**
     * レコード → 取込イベント。同じ指紋を 60 秒窓で集約。
     *
     * @param  array<int,array<string,mixed>>  $records
     * @return array<int,array<string,mixed>>
     */
    private function toEvents(array $records, string $path, int $generation, int $startOffset, CarbonImmutable $now): array
    {
        $minLevel = Severity::from(strtoupper((string) config('monitoring.collector.min_level', 'ERROR')));
        $infoPatterns = (array) config('monitoring.collector.info_patterns', []);
        $window = (int) config('monitoring.collector.aggregate_window_seconds', 60);
        $groups = [];
        $seq = 0;
        foreach ($records as $r) {
            $seq++;
            $sev = $r['severity'];
            if (! $sev) {
                continue; // DEBUG / 不明
            }
            if ($sev->rank() < $minLevel->rank()) {
                $allowed = false;
                foreach ($infoPatterns as $p) {
                    if (@preg_match($p, $r['message'])) {
                        $allowed = true;
                        break;
                    }
                }
                if (! $allowed) {
                    continue;
                }
            }
            $occurred = $r['timestamp'] ? CarbonImmutable::parse($r['timestamp'], 'Asia/Tokyo')->utc() : $now;
            $bucket = intdiv($occurred->getTimestamp(), max(1, $window));
            $key = $r['fingerprint_material'].'|'.$bucket;
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'schema_version' => 1,
                    'event_id' => \App\Monitoring\Support\Uuid::v5($this->collectorConfig['collector_uuid'], implode('|', [$path, $generation, $startOffset, $seq, hash('sha256', $r['fingerprint_material'].$r['sample'])])),
                    'application_code' => strtoupper((string) $this->collectorConfig['application_code']),
                    'node_code' => (string) $this->collectorConfig['node_code'],
                    'source_kind' => SourceKind::APPLICATION_LOG->value,
                    'severity' => $sev->value,
                    'error_code' => $r['error_code'],
                    'occurred_at' => $occurred->toIso8601String(),
                    'summary' => $r['message'] !== '' ? $r['message'] : $r['error_code'],
                    'title' => mb_substr(($r['exception']['class'] ?? $r['error_code']).': '.$r['message'], 0, 200),
                    'scope' => ['fp' => substr(hash('sha256', $r['fingerprint_material']), 0, 16), 'file' => basename($path)],
                    'evidence' => ['sample' => $r['sample'], 'exception' => $r['exception'], 'log_file' => basename($path)],
                    'metrics' => [],
                    'occurrence_delta' => 0,
                    'first_occurred_at' => $occurred->toIso8601String(),
                    'last_occurred_at' => $occurred->toIso8601String(),
                    'runbook_key' => 'RB-07',
                    'node_scoped' => false,
                ];
            }
            $groups[$key]['occurrence_delta']++;
            $groups[$key]['last_occurred_at'] = $occurred->toIso8601String();
        }
        foreach ($groups as &$g) {
            $g['metrics'] = ['count' => $g['occurrence_delta']];
        }

        return array_values($groups);
    }

    private function headHash(string $path, int $len): string
    {
        $fh = fopen($path, 'r');
        $head = $len > 0 ? (string) fread($fh, $len) : '';
        fclose($fh);

        return hash('sha256', $head);
    }

    private function statePath(): string
    {
        $dep = preg_replace('/[^A-Za-z0-9_.-]/', '_', (string) config('monitoring.deployment.id'));

        return sprintf('%s/%s/%s.%s.collector.json', rtrim($this->stateDir, '/'), $dep, strtoupper((string) $this->collectorConfig['application_code']), (string) $this->collectorConfig['node_code']);
    }

    /** @return array<string,mixed> */
    private function loadState(): array
    {
        $p = $this->statePath();
        $s = is_file($p) ? json_decode((string) file_get_contents($p), true) : null;

        return is_array($s) ? $s : ['files' => []];
    }

    private function saveState(array $state): void
    {
        $p = $this->statePath();
        if (! is_dir(dirname($p))) {
            mkdir(dirname($p), 0750, true);
        }
        $tmp = $p.'.tmp';
        file_put_contents($tmp, CanonicalJson::encode($state), LOCK_EX);
        rename($tmp, $p);
    }

    /**
     * log_files のワイルドカード展開。日次ログ（laravel-YYYY-MM-DD.log）は直近 2 日に更新のあるファイルだけを対象にし、古い日付を読み直さない。
     *
     * @param  string[]  $patterns
     * @return string[]
     */
    public static function expandLogFiles(array $patterns): array
    {
        $out = [];
        $recent = time() - 2 * 86400;
        foreach ($patterns as $pattern) {
            if (! str_contains($pattern, '*') && ! str_contains($pattern, '?')) {
                $out[] = $pattern;

                continue;
            }
            foreach ((array) glob($pattern, GLOB_NOSORT) as $f) {
                if (is_file($f) && (@filemtime($f) ?: 0) >= $recent) {
                    $out[] = $f;
                }
            }
        }
        sort($out);

        return array_values(array_unique($out));
    }
}
