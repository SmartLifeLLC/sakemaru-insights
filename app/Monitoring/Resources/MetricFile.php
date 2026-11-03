<?php

namespace App\Monitoring\Resources;

use Carbon\CarbonImmutable;

/**
 * ローカル保存（v2 第5章 / V-061〜065）。
 * - 30 秒値: 日別（JST）固定長ファイル `{node}/{YYYY-MM-DD}.m30`。ヘッダ 16 バイト＋列指向 float32（列数 × 2,880 枠）。欠損枠は NaN。
 *   13 列 × 2,880 × 4 バイト ≒ 150KB/日/台。書き込みは該当枠の上書きだけで、ファイルは伸びない。
 * - 1 秒バースト: 日別追記ファイル `{node}/{YYYY-MM-DD}.b1`（uint32 時刻＋float32 × 列数）。警告域の前後だけ書く。
 * - 削除は日別ファイルの unlink だけ（DB の DELETE は発生しない）。
 */
final class MetricFile
{
    public const COLUMNS = ['cpu', 'iowait', 'steal', 'load1', 'load5', 'mem_used_pct', 'swap_used_pct', 'disk_used_pct_max', 'io_util_max', 'net_rx_mbps', 'net_tx_mbps', 'psi_cpu', 'psi_io'];

    private const MAGIC = 'SKM1';

    private const HEADER = 16;

    public function __construct(private readonly string $dir, private readonly int $interval = 30)
    {
        if (! is_dir($this->dir)) {
            @mkdir($this->dir, 0750, true);
        }
    }

    public static function forNode(string $nodeCode): self
    {
        $safe = preg_replace('/[^A-Za-z0-9_.-]/', '_', $nodeCode);

        return new self(rtrim((string) config('monitoring.resources.data_dir'), '/').'/'.$safe, (int) config('monitoring.resources.sample_seconds', 30));
    }

    public function slotsPerDay(): int
    {
        return intdiv(86400, $this->interval);
    }

    public function fileSize(): int
    {
        return self::HEADER + count(self::COLUMNS) * $this->slotsPerDay() * 4;
    }

    public static function jstDate(int $ts): string
    {
        return CarbonImmutable::createFromTimestamp($ts, 'Asia/Tokyo')->toDateString();
    }

    public function path(string $date, string $ext = 'm30'): string
    {
        return $this->dir.'/'.$date.'.'.$ext;
    }

    /** @param array<string,?float> $values */
    public function write(int $ts, array $values): void
    {
        $date = self::jstDate($ts);
        $midnight = CarbonImmutable::parse($date, 'Asia/Tokyo')->getTimestamp();
        $slot = intdiv($ts - $midnight, $this->interval);
        $path = $this->path($date);
        if (! is_file($path)) {
            $this->create($path);
        }
        $fh = fopen($path, 'r+b');
        if (! $fh) {
            return;
        }
        try {
            flock($fh, LOCK_EX);
            $per = $this->slotsPerDay();
            foreach (self::COLUMNS as $c => $name) {
                fseek($fh, self::HEADER + ($c * $per + $slot) * 4);
                fwrite($fh, pack('g', self::f($values[$name] ?? null)));
            }
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    private function create(string $path): void
    {
        $tmp = $path.'.tmp'.getmypid();
        $fh = fopen($tmp, 'wb');
        fwrite($fh, self::MAGIC.pack('vvV', count(self::COLUMNS), $this->interval, 0).str_repeat("\0", 4));
        $nanRow = str_repeat(pack('g', NAN), $this->slotsPerDay());
        foreach (self::COLUMNS as $_) {
            fwrite($fh, $nanRow);
        }
        fclose($fh);
        if (! @rename($tmp, $path)) { // 別プロセスが先に作った
            @unlink($tmp);
        }
    }

    /**
     * 範囲内の 30 秒値（全列 NaN の枠は返さない）。
     *
     * @return array{t:int[],cols:array<string,array<int,?float>>}
     */
    public function read(int $fromTs, int $toTs): array
    {
        $out = ['t' => [], 'cols' => array_fill_keys(self::COLUMNS, [])];
        $per = $this->slotsPerDay();
        for ($day = CarbonImmutable::createFromTimestamp($fromTs, 'Asia/Tokyo')->startOfDay(); $day->getTimestamp() <= $toTs; $day = $day->addDay()) {
            $path = $this->path($day->toDateString());
            if (! is_file($path)) {
                continue;
            }
            $raw = file_get_contents($path);
            if (strlen($raw) < $this->fileSize() || substr($raw, 0, 4) !== self::MAGIC) {
                continue;
            }
            $base = $day->getTimestamp();
            $cols = [];
            foreach (self::COLUMNS as $c => $name) {
                $cols[$name] = array_values(unpack('g*', substr($raw, self::HEADER + $c * $per * 4, $per * 4)));
            }
            for ($s = 0; $s < $per; $s++) {
                $ts = $base + $s * $this->interval;
                if ($ts < $fromTs || $ts > $toTs) {
                    continue;
                }
                $row = [];
                $any = false;
                foreach (self::COLUMNS as $name) {
                    $v = $cols[$name][$s];
                    $row[$name] = is_nan($v) ? null : round($v, 3);
                    $any = $any || $row[$name] !== null;
                }
                if (! $any) {
                    continue;
                }
                $out['t'][] = $ts;
                foreach ($row as $name => $v) {
                    $out['cols'][$name][] = $v;
                }
            }
        }

        return $out;
    }

    /** @param array<string,?float> $values */
    public function appendBurst(int $ts, array $values): void
    {
        $rec = pack('V', $ts);
        foreach (self::COLUMNS as $name) {
            $rec .= pack('g', self::f($values[$name] ?? null));
        }
        file_put_contents($this->path(self::jstDate($ts), 'b1'), $rec, FILE_APPEND | LOCK_EX);
    }

    /** @return array{t:int[],cols:array<string,array<int,?float>>} */
    public function readBurst(int $fromTs, int $toTs): array
    {
        $out = ['t' => [], 'cols' => array_fill_keys(self::COLUMNS, [])];
        $size = 4 + 4 * count(self::COLUMNS);
        for ($day = CarbonImmutable::createFromTimestamp($fromTs, 'Asia/Tokyo')->startOfDay(); $day->getTimestamp() <= $toTs; $day = $day->addDay()) {
            $path = $this->path($day->toDateString(), 'b1');
            if (! is_file($path)) {
                continue;
            }
            $raw = file_get_contents($path);
            for ($o = 0; $o + $size <= strlen($raw); $o += $size) {
                $ts = unpack('V', substr($raw, $o, 4))[1];
                if ($ts < $fromTs || $ts > $toTs) {
                    continue;
                }
                $vals = array_values(unpack('g*', substr($raw, $o + 4, $size - 4)));
                $out['t'][] = $ts;
                foreach (self::COLUMNS as $i => $name) {
                    $out['cols'][$name][] = is_nan($vals[$i]) ? null : round($vals[$i], 3);
                }
            }
        }

        return $out;
    }

    /** 保存日数を過ぎた日別ファイルを unlink する。 @return string[] 削除したファイル名 */
    public function prune(int $days, ?int $nowTs = null): array
    {
        $limit = CarbonImmutable::createFromTimestamp($nowTs ?? time(), 'Asia/Tokyo')->startOfDay()->subDays($days)->toDateString();
        $deleted = [];
        foreach ((array) glob($this->dir.'/*.{m30,b1}', GLOB_BRACE) as $f) {
            if (preg_match('/(\d{4}-\d{2}-\d{2})\.(m30|b1)$/', $f, $m) && $m[1] < $limit && @unlink($f)) {
                $deleted[] = basename($f);
            }
        }

        return $deleted;
    }

    private static function f(?float $v): float
    {
        return $v === null || ! is_finite($v) ? NAN : $v;
    }
}
