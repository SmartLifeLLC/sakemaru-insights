<?php

namespace App\Monitoring\Collectors;

use App\Monitoring\Support\CanonicalJson;

/**
 * ローカル退避（仕様書 6.2 / 15.3）。送信前に必ず退避し、ACK 済みだけを解放する。
 * deployment_id・ノードごとにディレクトリを分ける。容量上限で最古 INFO から破棄し、失った件数を記録する。
 */
class Spool
{
    public function __construct(private readonly string $dir, private readonly int $maxBytes, private readonly int $errorReserveBytes) {}

    public static function forCollector(string $collectorUuid): self
    {
        $base = rtrim((string) config('monitoring.collector.spool_dir'), '/');
        $dep = preg_replace('/[^A-Za-z0-9_.-]/', '_', (string) config('monitoring.deployment.id'));

        return new self("{$base}/{$dep}/{$collectorUuid}", (int) config('monitoring.collector.spool_max_bytes'), (int) config('monitoring.collector.spool_error_reserve_bytes'));
    }

    public function dir(): string
    {
        return $this->dir;
    }

    /** @param array<int,array<string,mixed>> $events */
    public function append(array $events): string
    {
        if (! is_dir($this->dir)) {
            mkdir($this->dir, 0750, true);
        }
        $this->enforceCapacity($events);
        $path = sprintf('%s/%s-%s.jsonl', $this->dir, gmdate('YmdHis'), bin2hex(random_bytes(4)));
        $fh = fopen($path, 'x');
        foreach ($events as $e) {
            fwrite($fh, CanonicalJson::encode($e)."\n");
        }
        fflush($fh);
        fclose($fh);

        return $path;
    }

    /** @return string[] 古い順のファイル一覧 */
    public function files(): array
    {
        if (! is_dir($this->dir)) {
            return [];
        }
        $files = glob($this->dir.'/*.jsonl') ?: [];
        sort($files);

        return $files;
    }

    /** @return array<int,array<string,mixed>> */
    public function read(string $path): array
    {
        $out = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $j = json_decode($line, true);
            if (is_array($j)) {
                $out[] = $j;
            }
        }

        return $out;
    }

    /** ACK 済み ID を除いて書き戻す。全件 ACK なら削除。 */
    public function release(string $path, array $ackedIds): void
    {
        $remain = array_values(array_filter($this->read($path), fn ($e) => ! in_array($e['event_id'] ?? '', $ackedIds, true)));
        if (! $remain) {
            @unlink($path);

            return;
        }
        file_put_contents($path, implode("\n", array_map(fn ($e) => CanonicalJson::encode($e), $remain))."\n", LOCK_EX);
    }

    public function bytes(): int
    {
        $b = 0;
        foreach ($this->files() as $f) {
            $b += (int) filesize($f);
        }

        return $b;
    }

    public function ratio(): float
    {
        return $this->maxBytes > 0 ? $this->bytes() / $this->maxBytes : 0.0;
    }

    /** @param array<int,array<string,mixed>> $incoming */
    private function enforceCapacity(array $incoming): void
    {
        $hasError = (bool) array_filter($incoming, fn ($e) => in_array($e['severity'] ?? '', ['ERROR', 'CRITICAL'], true));
        $limit = $hasError ? $this->maxBytes : max(0, $this->maxBytes - $this->errorReserveBytes);
        $dropped = 0;
        $droppedFrom = null;
        while ($this->bytes() > $limit) {
            $files = $this->files();
            if (! $files) {
                break;
            }
            $oldest = $files[0];
            $events = $this->read($oldest);
            $keep = array_values(array_filter($events, fn ($e) => in_array($e['severity'] ?? '', ['ERROR', 'CRITICAL'], true)));
            $dropped += count($events) - count($keep);
            $droppedFrom ??= $events[0]['occurred_at'] ?? null;
            if ($keep === $events) {
                break; // ERROR だけが残っている: これ以上破棄しない
            }
            if (! $keep) {
                @unlink($oldest);
            } else {
                file_put_contents($oldest, implode("\n", array_map(fn ($e) => CanonicalJson::encode($e), $keep))."\n", LOCK_EX);
            }
        }
        if ($dropped > 0) {
            @file_put_contents($this->dir.'/dropped.log', sprintf("%s dropped=%d since=%s\n", gmdate('c'), $dropped, $droppedFrom), FILE_APPEND | LOCK_EX);
        }
    }
}
