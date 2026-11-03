<?php

namespace App\Monitoring\Resources;

/**
 * /proc の直読み（v2 第5章）。外部コマンドを起動しない（ss -s / df -i だけは 60 秒に 1 回、別メソッド）。
 * proc_root を差し替えられるので、固定サンプル（カーネル 5.x / 6.x）でテストできる（V-060）。
 * 返すのは累積カウンタの生値。差分（使用率・IOPS 等）は ResourceSampler が計算する。
 */
class ProcReader
{
    public function __construct(private readonly string $root = '/proc') {}

    public static function fromConfig(): self
    {
        return new self(rtrim((string) config('monitoring.resources.proc_root', '/proc'), '/'));
    }

    private function read(string $rel): ?string
    {
        $path = $this->root.'/'.$rel;
        $s = @file_get_contents($path);

        return $s === false ? null : $s;
    }

    /** @return array{user:int,nice:int,system:int,idle:int,iowait:int,irq:int,softirq:int,steal:int,total:int,cores:int,ctxt:?int,procs_running:?int}|null */
    public function cpu(): ?array
    {
        $s = $this->read('stat');
        if ($s === null) {
            return null;
        }
        $out = null;
        $cores = 0;
        $ctxt = null;
        $running = null;
        foreach (explode("\n", $s) as $line) {
            if (str_starts_with($line, 'cpu ')) {
                $f = array_map('intval', preg_split('/\s+/', trim(substr($line, 4))));
                $f = array_pad($f, 10, 0);
                // user nice system idle iowait irq softirq steal guest guest_nice（guest は user に含まれるので total に足さない）
                $out = ['user' => $f[0], 'nice' => $f[1], 'system' => $f[2], 'idle' => $f[3], 'iowait' => $f[4], 'irq' => $f[5], 'softirq' => $f[6], 'steal' => $f[7]];
                $out['total'] = array_sum(array_slice($f, 0, 8));
            } elseif (preg_match('/^cpu\d+\s/', $line)) {
                $cores++;
            } elseif (str_starts_with($line, 'ctxt ')) {
                $ctxt = (int) substr($line, 5);
            } elseif (str_starts_with($line, 'procs_running ')) {
                $running = (int) substr($line, 14);
            }
        }
        if (! $out) {
            return null;
        }

        return $out + ['cores' => max(1, $cores), 'ctxt' => $ctxt, 'procs_running' => $running];
    }

    /** @return array{total_kb:int,available_kb:int,free_kb:int,buffers_kb:int,cached_kb:int,swap_total_kb:int,swap_free_kb:int}|null */
    public function memory(): ?array
    {
        $s = $this->read('meminfo');
        if ($s === null) {
            return null;
        }
        $m = [];
        foreach (explode("\n", $s) as $line) {
            if (preg_match('/^(\w+(?:\(\w+\))?):\s+(\d+)/', $line, $x)) {
                $m[$x[1]] = (int) $x[2];
            }
        }
        if (! isset($m['MemTotal'])) {
            return null;
        }
        // MemAvailable は 3.14 以降。無ければ free + buffers + cached で近似
        $avail = $m['MemAvailable'] ?? (($m['MemFree'] ?? 0) + ($m['Buffers'] ?? 0) + ($m['Cached'] ?? 0));

        return ['total_kb' => $m['MemTotal'], 'available_kb' => $avail, 'free_kb' => $m['MemFree'] ?? 0, 'buffers_kb' => $m['Buffers'] ?? 0,
            'cached_kb' => $m['Cached'] ?? 0, 'swap_total_kb' => $m['SwapTotal'] ?? 0, 'swap_free_kb' => $m['SwapFree'] ?? 0];
    }

    /** @return array{load1:float,load5:float,load15:float}|null */
    public function load(): ?array
    {
        $s = $this->read('loadavg');
        if ($s === null) {
            return null;
        }
        $f = preg_split('/\s+/', trim($s));

        return count($f) >= 3 ? ['load1' => (float) $f[0], 'load5' => (float) $f[1], 'load15' => (float) $f[2]] : null;
    }

    /** PSI（4.20 以降、無効なカーネルでは null）。some avg10 を返す。 @return array{cpu:?float,io:?float,memory:?float} */
    public function pressure(): array
    {
        $out = ['cpu' => null, 'io' => null, 'memory' => null];
        foreach (array_keys($out) as $k) {
            $s = $this->read('pressure/'.$k);
            if ($s !== null && preg_match('/^some\s+avg10=([\d.]+)/m', $s, $m)) {
                $out[$k] = (float) $m[1];
            }
        }

        return $out;
    }

    /**
     * ブロックデバイス別の累積値（パーティションと除外デバイスは除く）。
     *
     * @return array<string,array{reads:int,read_sectors:int,writes:int,write_sectors:int,io_ms:int}>
     */
    public function diskstats(?string $excludeRegex = null): array
    {
        $s = $this->read('diskstats');
        if ($s === null) {
            return [];
        }
        $excludeRegex ??= (string) config('monitoring.resources.exclude_devices', '/^(loop|ram|sr|dm-|zram)/');
        $out = [];
        foreach (explode("\n", trim($s)) as $line) {
            $f = preg_split('/\s+/', trim($line));
            if (count($f) < 14) {
                continue;
            }
            $dev = $f[2];
            if (($excludeRegex && preg_match($excludeRegex, $dev)) || $this->isPartition($dev)) {
                continue;
            }
            $out[$dev] = ['reads' => (int) $f[3], 'read_sectors' => (int) $f[5], 'writes' => (int) $f[7], 'write_sectors' => (int) $f[9], 'io_ms' => (int) $f[12]];
        }

        return $out;
    }

    private function isPartition(string $dev): bool
    {
        // sda1 / xvda1 / vda2 / nvme0n1p1 / mmcblk0p1。nvme0n1 自体はデバイス。
        return (bool) preg_match('/^(?:(?:s|xv|v|h)d[a-z]+\d+|nvme\d+n\d+p\d+|mmcblk\d+p\d+)$/', $dev);
    }

    /** @return array<string,array{rx_bytes:int,tx_bytes:int,rx_errs:int,tx_errs:int}> */
    public function network(?string $excludeRegex = null): array
    {
        $s = $this->read('net/dev');
        if ($s === null) {
            return [];
        }
        $excludeRegex ??= (string) config('monitoring.resources.exclude_interfaces', '/^(lo|docker|veth|br-|virbr)/');
        $out = [];
        foreach (explode("\n", $s) as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }
            [$if, $rest] = array_map('trim', explode(':', $line, 2));
            if ($if === '' || ($excludeRegex && preg_match($excludeRegex, $if))) {
                continue;
            }
            $f = preg_split('/\s+/', $rest);
            if (count($f) < 16) {
                continue;
            }
            $out[$if] = ['rx_bytes' => (int) $f[0], 'rx_errs' => (int) $f[2], 'tx_bytes' => (int) $f[8], 'tx_errs' => (int) $f[10]];
        }

        return $out;
    }

    /**
     * 監視対象のマウント（/proc/mounts から。除外 fstype・接頭辞・重複デバイスを除く）。
     *
     * @return array<int,array{mount:string,device:string,fstype:string}>
     */
    public function mounts(): array
    {
        $s = $this->read('mounts');
        if ($s === null) {
            return [];
        }
        $exFs = (array) config('monitoring.resources.exclude_fstypes', []);
        $exPrefix = (array) config('monitoring.resources.exclude_mount_prefixes', []);
        $out = [];
        $seen = [];
        foreach (explode("\n", trim($s)) as $line) {
            $f = preg_split('/\s+/', trim($line));
            if (count($f) < 3) {
                continue;
            }
            [$dev, $mnt, $fs] = $f;
            $mnt = str_replace('\040', ' ', $mnt);
            if (in_array($fs, $exFs, true) || isset($seen[$dev.'|'.$mnt])) {
                continue;
            }
            foreach ($exPrefix as $p) {
                if (str_starts_with($mnt.'/', $p)) {
                    continue 2;
                }
            }
            if (isset($seen['dev:'.$dev]) && str_starts_with($dev, '/dev/')) {
                continue; // bind mount（同一デバイスの二重計上を避ける）
            }
            $seen[$dev.'|'.$mnt] = true;
            $seen['dev:'.$dev] = true;
            $out[] = ['mount' => $mnt, 'device' => $dev, 'fstype' => $fs];
        }

        return $out;
    }

    /**
     * マウント別の使用率（statvfs 相当。PHP の disk_*_space）。inode は df -i の結果（60 秒に 1 回、任意）を合わせる。
     *
     * @param  array<string,float>  $inodePct  mount => inode 使用率
     * @return array<int,array{mount:string,fstype:string,size_gb:float,free_gb:float,used_pct:float,inode_used_pct:?float}>
     */
    public function diskUsage(array $mounts, array $inodePct = []): array
    {
        $out = [];
        foreach ($mounts as $m) {
            $total = @disk_total_space($m['mount']);
            $free = @disk_free_space($m['mount']);
            if (! $total || $free === false) {
                continue;
            }
            $out[] = ['mount' => $m['mount'], 'fstype' => $m['fstype'], 'size_gb' => round($total / 1073741824, 1), 'free_gb' => round($free / 1073741824, 1),
                'used_pct' => round(($total - $free) / $total * 100, 1), 'inode_used_pct' => $inodePct[$m['mount']] ?? null];
        }

        return $out;
    }

    /** df -iP（60 秒に 1 回）。失敗しても資源収集は止めない。 @return array<string,float> */
    public static function inodeUsage(): array
    {
        $out = [];
        $lines = [];
        @exec('LC_ALL=C timeout 3 df -iP 2>/dev/null', $lines);
        foreach (array_slice($lines, 1) as $line) {
            $f = preg_split('/\s+/', trim($line));
            if (count($f) >= 6 && preg_match('/^(\d+)%$/', $f[4], $m)) {
                $out[implode(' ', array_slice($f, 5))] = (float) $m[1];
            }
        }

        return $out;
    }

    /** ss -s（60 秒に 1 回）。 @return array{tcp_estab:?int,tcp_timewait:?int,tcp_total:?int} */
    public static function parseSocketSummary(string $s): array
    {
        $out = ['tcp_estab' => null, 'tcp_timewait' => null, 'tcp_total' => null];
        if (preg_match('/TCP:\s+(\d+)\s+\(estab\s+(\d+),\s+closed\s+\d+,\s+orphaned\s+\d+,\s+(?:synrecv\s+\d+,\s+)?timewait\s+(\d+)/', $s, $m)) {
            $out = ['tcp_total' => (int) $m[1], 'tcp_estab' => (int) $m[2], 'tcp_timewait' => (int) $m[3]];
        }

        return $out;
    }

    public static function socketSummary(): array
    {
        $out = @shell_exec('LC_ALL=C timeout 3 ss -s 2>/dev/null');

        return is_string($out) ? self::parseSocketSummary($out) : ['tcp_estab' => null, 'tcp_timewait' => null, 'tcp_total' => null];
    }

    public function kernel(): ?string
    {
        $s = $this->read('sys/kernel/osrelease');

        return $s === null ? null : trim($s);
    }

    /**
     * 監視対象プロセスの件数（/proc/<pid>/comm と cmdline を読むだけ。外部コマンドは使わない）。
     *
     * @param  array<string,array{comm?:string[],prefix?:bool,cmdline?:string}>  $defs
     * @return array<string,int> キー => 件数
     */
    public function processes(array $defs): array
    {
        $out = array_fill_keys(array_keys($defs), 0);
        if (! $defs) {
            return $out;
        }
        foreach ((array) @scandir($this->root) as $pid) {
            if (! ctype_digit((string) $pid)) {
                continue;
            }
            $comm = $this->read($pid.'/comm');
            if ($comm === null) {
                continue; // 走査中に終了したプロセス
            }
            $comm = trim($comm);
            $cmd = null;
            foreach ($defs as $key => $d) {
                $hit = false;
                foreach ((array) ($d['comm'] ?? []) as $name) {
                    if ($comm === $name || (! empty($d['prefix']) && str_starts_with($comm, $name))) {
                        $hit = true;
                        break;
                    }
                }
                if (! $hit && ! empty($d['cmdline']) && str_starts_with($comm, 'python')) {
                    $cmd ??= str_replace("\0", ' ', (string) $this->read($pid.'/cmdline'));
                    $hit = str_contains($cmd, (string) $d['cmdline']);
                }
                if ($hit) {
                    $out[$key]++;
                }
            }
        }

        return $out;
    }
}
