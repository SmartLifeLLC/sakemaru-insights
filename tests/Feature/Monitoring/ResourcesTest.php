<?php

namespace Tests\Feature\Monitoring;

use App\Models\Monitoring\Incident;
use App\Models\Monitoring\Node;
use App\Models\Monitoring\ServerMetric;
use App\Monitoring\Checks\Monitor\MetricsStaleCheck;
use App\Monitoring\Resources\MetricFile;
use App\Monitoring\Resources\MetricsIngestService;
use App\Monitoring\Resources\MetricsShipper;
use App\Monitoring\Resources\ProcReader;
use App\Monitoring\Resources\ResourceSampler;
use App\Monitoring\Resources\ThresholdEvaluator;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\CheckRunner;
use App\Monitoring\Services\SourceReader;
use App\Monitoring\Support\CanonicalJson;
use Carbon\CarbonImmutable;

/** v2 V-060〜072: サーバー資源の収集・保存・判定 */
class ResourcesTest extends MonitoringTestCase
{
    private string $tmp;

    /** 擬似 /proc の累積カウンタ */
    private array $acc = ['user' => 0, 'system' => 0, 'idle' => 0, 'iowait' => 0, 'steal' => 0];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootRegistries();
        $this->tmp = sys_get_temp_dir().'/mon-res-'.bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);
        config()->set('monitoring.resources.data_dir', $this->tmp.'/metrics');
        config()->set('monitoring.collector.state_dir', $this->tmp.'/state');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->tmp));
        parent::tearDown();
    }

    private function writeProc(string $dir, array $files): string
    {
        foreach ($files as $name => $content) {
            @mkdir(dirname($dir.'/'.$name), 0777, true);
            file_put_contents($dir.'/'.$name, $content);
        }

        return $dir;
    }

    /** 1 秒（cores=2、USER_HZ=100）進めたスナップショット */
    private function snap(int $seconds, float $busyPct, float $iowaitPct = 0, float $memAvailPct = 60, float $load1 = 0.5): array
    {
        $j = 200 * $seconds;
        $this->acc['user'] += (int) round($j * $busyPct / 100);
        $this->acc['iowait'] += (int) round($j * $iowaitPct / 100);
        $this->acc['idle'] += $j - (int) round($j * $busyPct / 100) - (int) round($j * $iowaitPct / 100);
        $total = array_sum($this->acc);

        return [
            'cpu' => ['user' => $this->acc['user'], 'nice' => 0, 'system' => 0, 'idle' => $this->acc['idle'], 'iowait' => $this->acc['iowait'], 'irq' => 0, 'softirq' => 0, 'steal' => 0, 'total' => $total, 'cores' => 2],
            'memory' => ['total_kb' => 8_000_000, 'available_kb' => (int) (8_000_000 * $memAvailPct / 100), 'free_kb' => 0, 'buffers_kb' => 0, 'cached_kb' => 0, 'swap_total_kb' => 0, 'swap_free_kb' => 0],
            'load' => ['load1' => $load1, 'load5' => $load1, 'load15' => $load1],
            'pressure' => ['cpu' => 0.0, 'io' => 0.0, 'memory' => 0.0],
            'diskstats' => [], 'network' => [],
        ];
    }

    private function disks(float $pct = 40): callable
    {
        return fn () => [['mount' => '/', 'fstype' => 'ext4', 'size_gb' => 100.0, 'free_gb' => 100 - $pct, 'used_pct' => $pct, 'inode_used_pct' => 3.0]];
    }

    // ------------------------------------------------------------------ V-060 /proc のパース

    public function test_proc_parsing_kernel5_and_kernel6_samples(): void
    {
        $k6 = $this->writeProc($this->tmp.'/k6', [
            'stat' => "cpu  10132153 290696 3084719 46828483 16683 0 25195 0 175628 0\ncpu0 1393280 32966 572056 13343292 6130 0 17875 0 23933 0\ncpu1 1335766 28720 425690 13474016 2815 0 1868 0 23961 0\nintr 1\nctxt 1990473\nprocs_running 2\n",
            'meminfo' => "MemTotal:       16303428 kB\nMemFree:          802344 kB\nMemAvailable:    9032052 kB\nBuffers:          512312 kB\nCached:          7600044 kB\nSwapTotal:       2097148 kB\nSwapFree:        2097148 kB\n",
            'loadavg' => "0.52 0.58 0.59 1/389 12345\n",
            'pressure/cpu' => "some avg10=1.23 avg60=0.80 avg300=0.50 total=123456\nfull avg10=0.00 avg60=0.00 avg300=0.00 total=0\n",
            'pressure/io' => "some avg10=0.50 avg60=0.10 avg300=0.05 total=1\nfull avg10=0.40 avg60=0.10 avg300=0.05 total=1\n",
            'diskstats' => "   7       0 loop0 100 0 200 10 0 0 0 0 0 10 10 0 0 0 0\n 259       0 nvme0n1 191 0 9894 87 22 1 192 15 0 128 102 0 0 0 0 0 0\n 259       1 nvme0n1p1 80 0 4000 40 10 0 100 5 0 50 45 0 0 0 0\n   8       0 sda 5000 10 80000 900 2000 50 30000 600 0 1500 1500\n   8       1 sda1 4000 10 70000 800 1900 50 29000 590 0 1400 1400\n",
            'net/dev' => "Inter-|   Receive                                                |  Transmit\n face |bytes    packets errs drop fifo frame compressed multicast|bytes    packets errs drop fifo colls carrier compressed\n    lo: 1000 10 0 0 0 0 0 0 1000 10 0 0 0 0 0 0\n  eth0: 987654321 1234 1 0 0 0 0 0 123456789 2345 2 0 0 0 0 0\ndocker0: 5 1 0 0 0 0 0 0 5 1 0 0 0 0 0 0\n",
            'mounts' => "/dev/nvme0n1p1 / ext4 rw,relatime 0 0\nproc /proc proc rw 0 0\ntmpfs /run tmpfs rw 0 0\n/dev/nvme0n1p2 /var ext4 rw 0 0\n/dev/nvme0n1p2 /var/lib/docker ext4 rw 0 0\n/dev/loop1 /snap/core/1 squashfs ro 0 0\nserver:/x /mnt/nfs nfs4 rw 0 0\n",
            'sys/kernel/osrelease' => "6.8.0-1015-aws\n",
        ]);
        $r = new ProcReader($k6);
        $cpu = $r->cpu();
        $this->assertSame(2, $cpu['cores']);
        $this->assertSame(10132153 + 290696 + 3084719 + 46828483 + 16683 + 0 + 25195 + 0, $cpu['total'], 'guest は total に含めない');
        $this->assertSame(2, $cpu['procs_running']);
        $this->assertSame(9032052, $r->memory()['available_kb']);
        $this->assertSame(0.52, $r->load()['load1']);
        $this->assertSame(['cpu' => 1.23, 'io' => 0.5, 'memory' => null], $r->pressure());
        $this->assertSame(['nvme0n1', 'sda'], array_keys($r->diskstats()), 'loop とパーティションは除外');
        $this->assertSame(['eth0'], array_keys($r->network()), 'lo / docker は除外');
        $this->assertSame(987654321, $r->network()['eth0']['rx_bytes']);
        $this->assertSame(['/', '/var'], array_column($r->mounts(), 'mount'), 'proc・tmpfs・squashfs・NFS・bind 重複を除外');
        $this->assertSame('6.8.0-1015-aws', $r->kernel());

        // カーネル 5.x 相当（MemAvailable あり・PSI なし・diskstats 14 列）
        $k5 = $this->writeProc($this->tmp.'/k5', [
            'stat' => "cpu  100 0 50 800 50 0 0 0\ncpu0 100 0 50 800 50 0 0 0\n",
            'meminfo' => "MemTotal: 1000 kB\nMemFree: 100 kB\nBuffers: 50 kB\nCached: 250 kB\nSwapTotal: 0 kB\nSwapFree: 0 kB\n",
            'loadavg' => "1.00 0.50 0.25 1/100 1\n",
            'diskstats' => "   8       0 xvda 10 0 80 1 5 0 40 1 0 2 2\n 202 1 xvda1 1 0 8 0 1 0 8 0 0 1 1\n",
        ]);
        $r5 = new ProcReader($k5);
        $this->assertSame(1, $r5->cpu()['cores']);
        $this->assertSame(400, $r5->memory()['available_kb'], 'MemAvailable が無い場合は free+buffers+cached');
        $this->assertSame(['cpu' => null, 'io' => null, 'memory' => null], $r5->pressure());
        $this->assertSame(['xvda'], array_keys($r5->diskstats()));
        $this->assertSame([], (new ProcReader($this->tmp.'/none'))->network(), '読めないファイルは空');
    }

    public function test_compute_percentages_and_rates(): void
    {
        $a = ['cpu' => ['user' => 100, 'nice' => 0, 'system' => 50, 'idle' => 800, 'iowait' => 50, 'irq' => 0, 'softirq' => 0, 'steal' => 0, 'total' => 1000],
            'diskstats' => ['sda' => ['reads' => 0, 'read_sectors' => 0, 'writes' => 0, 'write_sectors' => 0, 'io_ms' => 0]],
            'network' => ['eth0' => ['rx_bytes' => 0, 'tx_bytes' => 0, 'rx_errs' => 0, 'tx_errs' => 0]]];
        $b = ['cpu' => ['user' => 400, 'nice' => 0, 'system' => 150, 'idle' => 1300, 'iowait' => 150, 'irq' => 0, 'softirq' => 0, 'steal' => 0, 'total' => 2000],
            'memory' => ['total_kb' => 1000, 'available_kb' => 250, 'swap_total_kb' => 100, 'swap_free_kb' => 75],
            'diskstats' => ['sda' => ['reads' => 300, 'read_sectors' => 61440, 'writes' => 150, 'write_sectors' => 0, 'io_ms' => 15000]],
            'network' => ['eth0' => ['rx_bytes' => 3_000_000, 'tx_bytes' => 300_000, 'rx_errs' => 0, 'tx_errs' => 0]]];
        $s = ResourceSampler::compute($a, $b, 30);
        $this->assertSame(30.0, $s['user']);
        $this->assertSame(10.0, $s['iowait']);
        $this->assertSame(40.0, $s['cpu'], 'cpu = 100 - idle - iowait');
        $this->assertSame(75.0, $s['mem_used_pct']);
        $this->assertSame(25.0, $s['swap_used_pct']);
        $this->assertSame(10.0, $s['io']['sda']['r_iops']);
        $this->assertSame(1.0, $s['io']['sda']['r_mbs']);
        $this->assertSame(50.0, $s['io_util_max']);
        $this->assertSame(100000, $s['net']['eth0']['rx_bps']);
        $this->assertSame(0.8, $s['net_rx_mbps']);
    }

    // ------------------------------------------------------------------ V-061〜063 ローカル保存

    public function test_one_day_at_30s_is_2880_slots_and_about_150kb_without_burst(): void
    {
        $file = MetricFile::forNode('web1');
        $sampler = new ResourceSampler($file, ['sample_seconds' => 30, 'burst' => ['enabled' => false]]);
        $day = CarbonImmutable::parse('2026-11-03 00:00:00', 'Asia/Tokyo')->getTimestamp();
        $sampler->tick($day - 30, $this->snap(0, 10), $this->disks());
        $aggs = 0;
        for ($i = 0; $i < 2880; $i++) {
            $r = $sampler->tick($day + $i * 30, $this->snap(30, 10 + ($i % 5)), $this->disks());
            $this->assertTrue($r['wrote30']);
            $aggs += $r['aggregate'] ? 1 : 0;
        }
        $path = $file->path('2026-11-03');
        $this->assertFileExists($path);
        $this->assertSame($file->fileSize(), filesize($path), '固定長');
        $this->assertEqualsWithDelta(150_000, filesize($path), 1000);
        $data = $file->read($day, $day + 86399);
        $this->assertCount(2880, $data['t']);
        $this->assertSame(40.0, $data['cols']['disk_used_pct_max'][0]);
        $this->assertSame(287, $aggs, '5 分集約は 288 枠（最後の枠は次の境界で確定）');
        $this->assertFileDoesNotExist($file->path('2026-11-03', 'b1'), 'バースト無効なら 1 秒値は書かない');
    }

    public function test_burst_writes_one_second_values_with_two_minutes_before_and_after_only_in_warning_zone(): void
    {
        $file = MetricFile::forNode('web1');
        $sampler = ResourceSampler::fromConfig($file);
        $t0 = CarbonImmutable::parse('2026-11-03 10:00:00', 'Asia/Tokyo')->getTimestamp();
        $sampler->tick($t0, $this->snap(0, 5), $this->disks());
        for ($s = 1; $s <= 300; $s++) { // 平常 5 分
            $sampler->tick($t0 + $s, $this->snap(1, 5), $this->disks());
        }
        $this->assertFileDoesNotExist($file->path('2026-11-03', 'b1'), '平常時はバーストを書かない');
        for ($s = 301; $s <= 310; $s++) { // 10 秒だけ CPU 95%
            $this->assertTrue($sampler->tick($t0 + $s, $this->snap(1, 95), $this->disks())['burst']);
        }
        for ($s = 311; $s <= 600; $s++) {
            $sampler->tick($t0 + $s, $this->snap(1, 5), $this->disks());
        }
        $b = $file->readBurst($t0, $t0 + 600);
        $this->assertSame($t0 + 181, $b['t'][0], '警告域の 120 秒前から');
        $this->assertSame($t0 + 430, end($b['t']), '警告域を出て 120 秒後まで');
        $this->assertCount(250, $b['t']);
        $this->assertSame(95.0, $b['cols']['cpu'][array_search($t0 + 305, $b['t'], true)]);
        $this->assertTrue($sampler->isWarningZone(['load1' => 3.1]), 'load > コア数(2)×1.5');
        $this->assertTrue($sampler->isWarningZone(['mem_available_pct' => 14]));
        $this->assertFalse($sampler->isWarningZone(['cpu' => 50, 'iowait' => 5, 'mem_available_pct' => 40, 'load1' => 2]));
    }

    public function test_day_rollover_switches_file_and_gaps_are_nan(): void
    {
        $file = MetricFile::forNode('web1');
        $sampler = new ResourceSampler($file, ['sample_seconds' => 30, 'burst' => ['enabled' => false]]);
        $mid = CarbonImmutable::parse('2026-11-04 00:00:00', 'Asia/Tokyo')->getTimestamp();
        $sampler->tick($mid - 90, $this->snap(0, 10), $this->disks());
        $sampler->tick($mid - 60, $this->snap(30, 10), $this->disks());
        // 23:59:30 を欠損させる（収集器停止）
        $sampler->tick($mid, $this->snap(60, 20), $this->disks());
        $sampler->tick($mid + 30, $this->snap(30, 30), $this->disks());
        $this->assertFileExists($file->path('2026-11-03'));
        $this->assertFileExists($file->path('2026-11-04'));
        $d = $file->read($mid - 90, $mid + 30);
        $this->assertSame([$mid - 60, $mid, $mid + 30], $d['t'], '欠損枠（23:59:30）は返さない');
        $this->assertSame([10.0, 20.0, 30.0], $d['cols']['cpu']);
    }

    public function test_prune_unlinks_only_files_older_than_60_days(): void
    {
        $file = MetricFile::forNode('web1');
        $now = CarbonImmutable::parse('2026-11-03 12:00', 'Asia/Tokyo');
        foreach ([61, 60, 59, 1] as $daysAgo) {
            $ts = $now->subDays($daysAgo)->getTimestamp();
            $file->write($ts, ['cpu' => 1.0]);
            $file->appendBurst($ts, ['cpu' => 1.0]);
        }
        $deleted = $file->prune(60, $now->getTimestamp());
        sort($deleted);
        $this->assertSame([$now->subDays(61)->toDateString().'.b1', $now->subDays(61)->toDateString().'.m30'], $deleted);
        $this->assertFileExists($file->path($now->subDays(60)->toDateString()));
        $this->assertSame(0, ServerMetric::count(), 'DB は触らない');
    }

    // ------------------------------------------------------------------ V-064 集約と受け取り

    public function test_aggregate_and_ingest_are_idempotent_and_node_self_registers(): void
    {
        $samples = [['cpu' => 10, 'mem_used_pct' => 40, 'mem_available_pct' => 60, 'load1' => 0.5], ['cpu' => 30, 'mem_used_pct' => 50, 'mem_available_pct' => 50, 'load1' => 1.5], ['cpu' => 20, 'mem_used_pct' => 45, 'mem_available_pct' => 55, 'load1' => 1.0]];
        $bucket = intdiv(CarbonImmutable::now('UTC')->subMinutes(10)->getTimestamp(), 300) * 300;
        $agg = ResourceSampler::aggregate($bucket, $samples, [['mount' => '/', 'used_pct' => 40.0]], ['tcp_estab' => 12], 2);
        $this->assertSame(20.0, $agg['cpu_avg']);
        $this->assertSame(30.0, $agg['cpu_max']);
        $this->assertSame(10.0, $agg['extra_json']['min']['cpu']);
        $this->assertEquals(20, $agg['extra_json']['last']['cpu']);
        $this->assertSame(3, $agg['sample_count']);

        $svc = app(MetricsIngestService::class);
        $payload = ['node' => ['code' => 'web1', 'role' => 'web', 'display_name' => 'Web 1 号機', 'os' => ['kernel' => '6.8', 'cores' => 2]], 'latest' => ['ts' => time(), 'cores' => 2, 'current' => ['cpu' => 20]], 'aggregates' => [$agg]];
        $r = $svc->ingest($payload);
        $svc->ingest($payload);
        $this->assertSame(1, ServerMetric::count(), '同じ枠の再送で重複しない');
        $this->assertSame(1, $r['stored']);
        $node = Node::query()->sole();
        $this->assertSame('Web 1 号機', $node->display_name);
        $this->assertSame('env', $node->registered_from);
        $this->assertSame(90, (int) $r['thresholds']['cpu_pct']['error']);
        $this->assertGreaterThan(now('UTC')->addDays(360), ServerMetric::sole()->expires_at, '365 日保持');

        // 画面で表示名を変えたら .env の値で上書きしない
        $node->update(['display_name' => '本番 Web', 'updated_user_id' => 1]);
        $svc->ingest($payload);
        $this->assertSame('本番 Web', $node->fresh()->display_name);

        // 不正な枠（5 分境界でない・未来）は捨てる
        $bad = $svc->ingest(['node' => ['code' => 'web1'], 'aggregates' => [['bucket_start' => $bucket + 7], ['bucket_start' => time() + 3600]]]);
        $this->assertSame(2, $bad['rejected']);
        $this->expectException(\InvalidArgumentException::class);
        $svc->ingest(['node' => ['code' => '../etc']]);
    }

    public function test_metrics_api_requires_signature_and_matching_node(): void
    {
        putenv('MONITOR_METRICS_SECRET=s3cret');
        $body = CanonicalJson::encode(['node' => ['code' => 'web1'], 'latest' => ['ts' => time(), 'current' => ['cpu' => 1]]]);
        $ts = (string) time();
        $send = fn (string $node, string $sig) => $this->call('POST', '/internal/monitor/metrics', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_MONITOR_NODE' => $node, 'HTTP_X_MONITOR_TIMESTAMP' => $ts, 'HTTP_X_MONITOR_SIGNATURE' => $sig], $body);
        $good = hash_hmac('sha256', $ts.'.'.$body, 's3cret');
        $send('web1', 'bad')->assertStatus(401);
        $send('web2', hash_hmac('sha256', $ts.'.'.$body, 's3cret'))->assertStatus(401)->assertJson(['error' => 'node_mismatch']);
        $send('web1', $good)->assertOk()->assertJson(['ok' => true]);
        $this->assertSame('web1', Node::query()->sole()->node_code);
        putenv('MONITOR_METRICS_SECRET');
    }

    public function test_shipper_keeps_pending_aggregates_when_sending_fails(): void
    {
        config()->set('monitoring.collector.ingest_url', 'https://insights.example.jp');
        putenv('MONITOR_METRICS_SECRET=s3cret');
        \Illuminate\Support\Facades\Http::fake(['insights.example.jp/*' => \Illuminate\Support\Facades\Http::sequence()->push('down', 503)->push(['ok' => true, 'thresholds' => []], 200)]);
        $shipper = app(MetricsShipper::class);
        $node = ['code' => 'web1', 'role' => 'web', 'display_name' => 'w'];
        $r1 = $shipper->ship($node, ['ts' => time()], [['bucket_start' => 300]]);
        $this->assertFalse($r1['ok']);
        $this->assertCount(1, $shipper->pending('web1'));
        $r2 = $shipper->ship($node, ['ts' => time()], [['bucket_start' => 600]]);
        $this->assertTrue($r2['ok']);
        $this->assertSame(2, $r2['sent'], '退避分も再送');
        $this->assertSame([], $shipper->pending('web1'));
        \Illuminate\Support\Facades\Http::assertSent(fn ($req) => $req->hasHeader('X-Monitor-Signature') && ! str_contains($req->body(), 's3cret'));
        putenv('MONITOR_METRICS_SECRET');
    }

    // ------------------------------------------------------------------ V-066〜069 しきい値判定

    private function latest(int $now, array $cpuSeries, array $extra = []): array
    {
        $t = [];
        foreach (array_keys($cpuSeries) as $i) {
            $t[] = $now - (count($cpuSeries) - 1 - $i) * 30;
        }

        return array_replace_recursive(['ts' => $now, 'cores' => 2, 'current' => ['cpu' => end($cpuSeries), 'mem_available_pct' => 50, 'load1' => 0.5, 'iowait' => 0, 'swap_used_pct' => 0],
            'disks' => [['mount' => '/', 'used_pct' => 40.0, 'inode_used_pct' => 3.0]],
            'recent' => ['t' => $t, 'cpu' => array_values($cpuSeries), 'iowait' => array_fill(0, count($t), 0), 'load1' => array_fill(0, count($t), 0.5),
                'mem_available_pct' => array_fill(0, count($t), 50), 'swap_used_pct' => array_fill(0, count($t), 0)]], $extra);
    }

    public function test_threshold_duration_five_minutes_vs_four_and_disk_immediate(): void
    {
        $th = ThresholdEvaluator::resolve(null);
        $now = 1_800_000_000;
        // 直近 5 分（11 点）すべて 95%
        $five = $this->latest($now, array_merge(array_fill(0, 9, 10), array_fill(0, 11, 95)));
        $this->assertSame('error', ThresholdEvaluator::evaluate($five, $th, $now)['cpu_pct']['level']);
        // 直近 4 分だけ 95%
        $four = $this->latest($now, array_merge(array_fill(0, 12, 10), array_fill(0, 8, 95)));
        $this->assertSame('ok', ThresholdEvaluator::evaluate($four, $th, $now)['cpu_pct']['level']);
        // 開始直後でデータが 5 分に満たない
        $short = $this->latest($now, array_fill(0, 6, 99));
        $this->assertSame('ok', ThresholdEvaluator::evaluate($short, $th, $now)['cpu_pct']['level']);
        // 85% 5 分は WARNING（80 以上）
        $warn = $this->latest($now, array_fill(0, 20, 85));
        $this->assertSame('warning', ThresholdEvaluator::evaluate($warn, $th, $now)['cpu_pct']['level']);
        // ディスクは即時。95% 以上は CRITICAL
        $disk = $this->latest($now, [10, 10], ['disks' => [['mount' => '/', 'used_pct' => 86.0, 'inode_used_pct' => 3.0], ['mount' => '/var', 'used_pct' => 96.0, 'inode_used_pct' => 3.0]]]);
        $r = ThresholdEvaluator::evaluate($disk, $th, $now);
        $this->assertSame('error', $r['disk_used_pct:/']['level']);
        $this->assertSame('critical', $r['disk_used_pct:/var']['level']);
        // メモリ空きは「下回る」判定、ノード別上書き・無効化
        $mem = $this->latest($now, [10, 10, 10], ['current' => ['mem_available_pct' => 4], 'recent' => ['mem_available_pct' => [4, 4, 4]]]);
        $this->assertSame('error', ThresholdEvaluator::evaluate($mem, $th, $now)['mem_available_pct']['level']);
        $custom = ThresholdEvaluator::resolve(['cpu_pct' => ['error' => 99], 'disk_used_pct' => ['disabled' => true]]);
        $this->assertSame('warning', ThresholdEvaluator::evaluate($five, $custom, $now)['cpu_pct']['level']);
        $this->assertArrayNotHasKey('disk_used_pct:/', ThresholdEvaluator::evaluate($disk, $custom, $now));
        // load はコア数で割る
        $load = $this->latest($now, array_fill(0, 22, 10), ['recent' => ['load1' => array_fill(0, 22, 4.2)], 'current' => ['load1' => 4.2]]);
        $this->assertSame('error', ThresholdEvaluator::evaluate($load, $th, $now)['load_per_core']['level'], '4.2 / 2 コア = 2.1 が 10 分');
    }

    public function test_node_resources_incident_needs_three_healthy_runs_to_recover(): void
    {
        $node = Node::query()->create(['node_code' => 'web1', 'display_name' => 'Web 1', 'role' => 'web', 'enabled' => true, 'registered_from' => 'env', 'last_heartbeat_at' => now('UTC')]);
        $set = function (array $cpu) use ($node) {
            $node->latest_json = $this->latest(now()->getTimestamp(), $cpu);
            $node->save();
        };
        $run = fn () => app(CheckRunner::class)->run(null, ['INSIGHTS:node.resources'], true, true);
        $set(array_fill(0, 20, 97));
        $run();
        $i = Incident::query()->where('check_key', 'node.resources')->sole();
        $this->assertSame('ERROR', $i->severity->value);
        $this->assertSame('RB-19', $i->runbook_key);
        $this->assertSame(['metric' => 'cpu_pct', 'node' => 'web1'], $i->scope_json);
        foreach ([1, 2] as $n) {
            $this->travel(2)->minutes();
            $set(array_fill(0, 20, 10));
            $run();
            $this->assertSame('ACTIVE', $i->fresh()->observation_state->value, "{$n} 回の正常では戻らない");
        }
        $this->travel(2)->minutes();
        $set(array_fill(0, 20, 10));
        $run();
        $this->assertSame('RECOVERED', $i->fresh()->observation_state->value);
        $this->assertSame(1, Incident::query()->where('check_key', 'node.resources')->count(), '指紋に日付を含めない');
    }

    public function test_metrics_stale_per_node(): void
    {
        $check = new MetricsStaleCheck;
        $eval = fn () => $check->evaluate(new CheckContext(SourceReader::fromConfig(), CarbonImmutable::now('UTC'), $check->definition()->defaultConfig))->observations;
        $this->assertSame('HEALTHY', $eval()[0]->outcome->value, 'ノードなし');
        $n = Node::query()->create(['node_code' => 'web1', 'display_name' => 'Web 1', 'role' => 'web', 'enabled' => true, 'registered_from' => 'env', 'last_heartbeat_at' => now('UTC')->subMinutes(4)]);
        $this->assertSame('WARNING', $eval()[0]->outcome->value);
        $n->update(['last_heartbeat_at' => now('UTC')->subMinutes(11)]);
        $this->assertSame('VIOLATION', $eval()[0]->outcome->value);
        $n->update(['enabled' => false]);
        $this->assertSame('HEALTHY', $eval()[0]->outcome->value, '無効ノードは判定しない');
    }

    // ------------------------------------------------------------------ V-070〜072

    public function test_socket_summary_parse_and_only_used_when_given(): void
    {
        $s = ProcReader::parseSocketSummary("Total: 350\nTCP:   45 (estab 30, closed 5, orphaned 0, timewait 4)\n");
        $this->assertSame(['tcp_total' => 45, 'tcp_estab' => 30, 'tcp_timewait' => 4], $s);
        $sampler = new ResourceSampler(MetricFile::forNode('web1'), ['sample_seconds' => 30, 'burst' => ['enabled' => false]]);
        $calls = 0;
        $sk = function () use (&$calls) {
            $calls++;

            return ['tcp_estab' => 1];
        };
        $t = 1_800_000_010;
        $sampler->tick($t, $this->snap(0, 1));
        $sampler->tick($t + 30, $this->snap(30, 1), null, $sk);
        $sampler->tick($t + 60, $this->snap(30, 1), null, null); // 60 秒に 1 回だけ渡される想定
        $this->assertSame(1, $calls);
        $this->assertSame(['tcp_estab' => 1], $sampler->latestPayload($t + 60)['sockets'], '渡されない回は直前値を保持');
    }

    public function test_sampling_cost_is_below_5ms(): void
    {
        if (! is_readable('/proc/stat')) {
            $this->markTestSkipped('Linux 以外');
        }
        $r = new ProcReader('/proc');
        $prev = null;
        $t0 = hrtime(true);
        $n = 300;
        for ($i = 0; $i < $n; $i++) {
            $snap = ['cpu' => $r->cpu(), 'memory' => $r->memory(), 'load' => $r->load(), 'pressure' => $r->pressure(), 'diskstats' => $r->diskstats(), 'network' => $r->network()];
            if ($prev) {
                ResourceSampler::compute($prev, $snap, 1);
            }
            $prev = $snap;
        }
        $ms = (hrtime(true) - $t0) / 1e6 / $n;
        $this->assertLessThan(5.0, $ms, sprintf('1 サンプル %.3fms', $ms));
    }

    public function test_mount_exclusion_is_configurable(): void
    {
        $dir = $this->writeProc($this->tmp.'/m', ['mounts' => "/dev/sda1 / ext4 rw 0 0\n/dev/sdb1 /data xfs rw 0 0\ntmpfs /dev/shm tmpfs rw 0 0\n"]);
        $this->assertSame(['/', '/data'], array_column((new ProcReader($dir))->mounts(), 'mount'));
        config()->set('monitoring.resources.exclude_fstypes', ['xfs']);
        config()->set('monitoring.resources.exclude_mount_prefixes', []);
        $this->assertSame(['/', '/dev/shm'], array_column((new ProcReader($dir))->mounts(), 'mount'));
    }

    // ------------------------------------------------------------------ プロセスの生存確認（Apache・PHP-FPM・supervisord）

    public function test_process_scan_reads_comm_and_cmdline(): void
    {
        $dir = $this->writeProc($this->tmp.'/pp', [
            '101/comm' => "httpd\n", '102/comm' => "httpd\n", '201/comm' => "php-fpm8.3\n",
            '301/comm' => "python3\n", '301/cmdline' => "/usr/bin/python3\0/usr/bin/supervisord\0-n\0",
            '302/comm' => "python3\n", '302/cmdline' => "/usr/bin/python3\0manage.py\0",
            '401/comm' => "bash\n", 'self/comm' => "php\n", '501/comm' => "cron\n",
        ]);
        $counts = (new ProcReader($dir))->processes((array) config('monitoring.resources.processes'));
        $this->assertSame(['apache' => 2, 'php-fpm' => 1, 'supervisor' => 1, 'cron' => 1], $counts);
        $this->assertSame(['apache' => 0, 'php-fpm' => 0, 'supervisor' => 0, 'cron' => 0], (new ProcReader($this->tmp.'/none'))->processes((array) config('monitoring.resources.processes')));

        $cfg = (array) config('monitoring.resources');
        $this->assertSame(['apache', 'supervisor', 'cron'], ResourceSampler::requiredProcesses($cfg, 'web'), 'mod_php 構成が既定: php-fpm は含めない');
        $this->assertSame(['supervisor', 'cron'], ResourceSampler::requiredProcesses($cfg, 'batch'));
        $cfg['required_processes'] = ['supervisor', 'unknown'];
        $this->assertSame(['supervisor'], ResourceSampler::requiredProcesses($cfg, 'web'), '.env の指定が優先、未知のキーは無視');
    }

    public function test_sampler_keeps_missing_since_until_process_returns(): void
    {
        $cfg = (array) config('monitoring.resources');
        $cfg['burst']['enabled'] = false;
        $cfg['required_processes_resolved'] = ['apache', 'php-fpm'];
        $sampler = new ResourceSampler(MetricFile::forNode('web1'), $cfg);
        $counts = ['apache' => 3, 'php-fpm' => 5, 'supervisor' => 0];
        $proc = function () use (&$counts) {
            return $counts;
        };
        $t = 1_800_000_010;
        $sampler->tick($t, $this->snap(0, 5));
        $sampler->tick($t + 30, $this->snap(30, 5), null, null, $proc);
        $p = $sampler->latestPayload($t + 30)['processes'];
        $this->assertNull($p['apache']['missing_since']);
        $this->assertFalse($p['supervisor']['required']);
        $counts['apache'] = 0;
        $sampler->tick($t + 60, $this->snap(30, 5), null, null, $proc);
        $sampler->tick($t + 90, $this->snap(30, 5), null, null, $proc);
        $p = $sampler->latestPayload($t + 90)['processes'];
        $this->assertSame(intdiv($t + 60, 30) * 30, $p['apache']['missing_since'], '0 件になった最初の時刻（30 秒枠）を保持し、2 回目以降も変えない');
        $this->assertSame(0, $p['apache']['count']);
        $counts['apache'] = 2;
        $sampler->tick($t + 120, $this->snap(30, 5), null, null, $proc);
        $this->assertNull($sampler->latestPayload($t + 120)['processes']['apache']['missing_since'], '復帰で解除');
    }

    public function test_required_process_down_for_a_minute_is_critical_and_recovers(): void
    {
        $th = ThresholdEvaluator::resolve(null);
        $now = 1_800_000_000;
        $l = ['ts' => $now, 'cores' => 2, 'current' => [], 'disks' => [], 'recent' => ['t' => []], 'processes' => [
            'apache' => ['count' => 0, 'required' => true, 'missing_since' => $now - 300],
            'php-fpm' => ['count' => 0, 'required' => true, 'missing_since' => $now - 299],
            'supervisor' => ['count' => 0, 'required' => false, 'missing_since' => $now - 600],
        ]];
        $r = ThresholdEvaluator::evaluate($l, $th, $now);
        $this->assertSame('error', $r['process_missing:apache']['level']);
        $this->assertSame('ok', $r['process_missing:php-fpm']['level'], '5 分未満は数えない');
        $this->assertArrayNotHasKey('process_missing:supervisor', $r, '必須でないプロセスは判定しない');
        $this->assertArrayNotHasKey('process_missing:apache', ThresholdEvaluator::evaluate($l, ThresholdEvaluator::resolve(['process_missing' => ['disabled' => true]]), $now));

        // チェック → 重大インシデント（指紋は node + process、日付なし）→ 3 回の正常で復旧
        $node = Node::query()->create(['node_code' => 'web2', 'display_name' => 'Web 2 号機', 'role' => 'web', 'enabled' => true, 'registered_from' => 'env', 'last_heartbeat_at' => now('UTC')]);
        $set = function (int $count) use ($node) {
            $ts = now()->getTimestamp();
            $node->latest_json = ['ts' => $ts, 'cores' => 2, 'current' => ['cpu' => 10], 'disks' => [], 'recent' => ['t' => []],
                'processes' => ['apache' => ['count' => $count, 'required' => true, 'missing_since' => $count ? null : $ts - 360]]];
            $node->save();
        };
        $run = fn () => app(\App\Monitoring\Services\CheckRunner::class)->run(null, ['INSIGHTS:node.resources'], true, true);
        $set(0);
        $run();
        $i = Incident::query()->where('check_key', 'node.resources')->sole();
        $this->assertSame('CRITICAL', $i->severity->value);
        $this->assertSame('NODE_PROCESS_DOWN', $i->error_code);
        $this->assertSame(['metric' => 'process', 'node' => 'web2', 'process' => 'apache'], $i->scope_json);
        $this->assertStringContainsString('Web 2 号機 で Apache の稼働が確認できません', $i->title);
        foreach ([1, 2, 3] as $n) {
            $this->travel(2)->minutes();
            $set(4);
            $run();
        }
        $this->assertSame('RECOVERED', $i->fresh()->observation_state->value);

        // 必須プロセスから外したら（例: mod_php 構成で php-fpm を外す）、その案件は「監視対象外」として復旧する（華 本番の回帰）
        $this->travel(2)->minutes();
        $ts = now()->getTimestamp();
        $node->latest_json = ['ts' => $ts, 'cores' => 2, 'current' => ['cpu' => 10], 'disks' => [], 'recent' => ['t' => []],
            'processes' => ['apache' => ['count' => 4, 'required' => true, 'missing_since' => null], 'php-fpm' => ['count' => 0, 'required' => true, 'missing_since' => $ts - 600]]];
        $node->save();
        $run();
        $fpm = Incident::query()->where('check_key', 'node.resources')->where('scope_json->process', 'php-fpm')->sole();
        $this->assertSame('ACTIVE', $fpm->observation_state->value);
        $node->latest_json = ['ts' => $ts + 60, 'cores' => 2, 'current' => ['cpu' => 10], 'disks' => [], 'recent' => ['t' => []],
            'processes' => ['apache' => ['count' => 4, 'required' => true, 'missing_since' => null]]];
        $node->save();
        foreach ([1, 2, 3] as $n) {
            $this->travel(2)->minutes();
            $node->latest_json = array_merge($node->latest_json, ['ts' => now()->getTimestamp()]);
            $node->save();
            $run();
        }
        $this->assertSame('RECOVERED', $fpm->fresh()->observation_state->value, '必須から外れた分は復旧');
    }

    public function test_ingest_keeps_only_known_processes(): void
    {
        app(MetricsIngestService::class)->ingest(['node' => ['code' => 'web1'], 'latest' => ['ts' => time(), 'current' => [], 'processes' => [
            'apache' => ['count' => '3', 'required' => true, 'missing_since' => null], 'evil' => ['count' => 1], 'php-fpm' => 'x',
        ]]]);
        $this->assertSame(['apache' => ['count' => 3, 'required' => true, 'missing_since' => null]], Node::query()->sole()->latest_json['processes']);
    }
}
