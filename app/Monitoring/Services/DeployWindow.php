<?php

namespace App\Monitoring\Services;

use App\Models\Monitoring\Setting;
use Carbon\CarbonImmutable;

/**
 * デプロイ窓（deploy.sh が開始・終了を記録）。ノードごとに 1 つ。
 * 自己監視の「n 分完了していない」「heartbeat が n 分停止」は、デプロイ窓に重なった時間を経過時間から差し引いて判定する
 * （timer を止めている間の停止を障害にしない）。業務チェック自体は timer が止まっている間は動かないので影響なし。
 * 窓は予定時間（--minutes）で自動的に閉じる（終了の記録が漏れても残らない）。
 */
class DeployWindow
{
    public const PREFIX = 'monitor.deploy_window.';

    public function __construct(private readonly AuditService $audit) {}

    /** @return array{node:string,started_at:CarbonImmutable,until:CarbonImmutable,reason:?string,actor_name:?string} */
    public function start(string $node, int $minutes, string $reason, array $actor): array
    {
        $now = CarbonImmutable::now('UTC');
        $minutes = max(5, min(240, $minutes));
        $value = ['node' => $node, 'started_at' => $now->toIso8601String(), 'until' => $now->addMinutes($minutes)->toIso8601String(), 'ended_at' => null, 'reason' => mb_substr($reason, 0, 200), 'actor_name' => $actor['name'] ?? null];
        $row = Setting::query()->firstOrNew(['setting_key' => self::PREFIX.$node]);
        $row->value_json = $value;
        $row->updated_name = $actor['name'] ?? null;
        $row->reason = $reason;
        $row->updated_at = $now;
        $row->save();
        $this->audit->record($actor, 'deploy.window.start', 'deploy_window', $node, null, ['until' => $value['until'], 'minutes' => $minutes], $reason);

        return $this->normalize($value);
    }

    public function end(string $node, array $actor): bool
    {
        $now = CarbonImmutable::now('UTC');
        $row = Setting::query()->where('setting_key', self::PREFIX.$node)->first();
        $v = (array) ($row?->value_json ?? []);
        if (! $row || empty($v['started_at']) || ! empty($v['ended_at'])) {
            return false;
        }
        $v['ended_at'] = $now->toIso8601String();
        $row->value_json = $v;
        $row->updated_at = $now;
        $row->save();
        $this->audit->record($actor, 'deploy.window.end', 'deploy_window', $node, null, ['ended_at' => $v['ended_at']], null);

        return true;
    }

    /** @return list<array{node:string,started_at:CarbonImmutable,until:CarbonImmutable,reason:?string,actor_name:?string}> 現在開いている窓 */
    public function active(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');

        return array_values(array_filter($this->windows(), fn ($w) => $w['started_at']->lte($now) && $w['until']->gt($now)));
    }

    /**
     * [since, now] のうちデプロイ窓（全ノードの和）に重なる秒数。
     * 直近の窓だけ見る（Setting は上書きなので 1 ノード 1 窓）。
     */
    public function overlapSeconds(CarbonImmutable $since, CarbonImmutable $now): int
    {
        $spans = [];
        foreach ($this->windows() as $w) {
            $a = max($w['started_at']->getTimestamp(), $since->getTimestamp());
            $b = min($w['until']->getTimestamp(), $now->getTimestamp());
            if ($b > $a) {
                $spans[] = [$a, $b];
            }
        }
        if ($spans === []) {
            return 0;
        }
        usort($spans, fn ($x, $y) => $x[0] <=> $y[0]);
        $total = 0;
        [$ca, $cb] = $spans[0];
        foreach (array_slice($spans, 1) as [$a, $b]) {
            if ($a <= $cb) {
                $cb = max($cb, $b);
            } else {
                $total += $cb - $ca;
                [$ca, $cb] = [$a, $b];
            }
        }

        return $total + ($cb - $ca);
    }

    /** 経過秒からデプロイ窓の分を差し引いた「実質の経過秒」 */
    public function effectiveAge(?int $ageSeconds, CarbonImmutable $now): ?int
    {
        if ($ageSeconds === null || $ageSeconds <= 0) {
            return $ageSeconds;
        }

        return max(0, $ageSeconds - $this->overlapSeconds($now->subSeconds($ageSeconds), $now));
    }

    /** @return list<array{node:string,started_at:CarbonImmutable,until:CarbonImmutable,reason:?string,actor_name:?string}> 終了済みは実終了時刻を until にする */
    private function windows(): array
    {
        $out = [];
        foreach (Setting::query()->where('setting_key', 'like', self::PREFIX.'%')->get() as $row) {
            $v = (array) $row->value_json;
            if (empty($v['started_at']) || empty($v['until'])) {
                continue;
            }
            $out[] = $this->normalize($v);
        }

        return $out;
    }

    private function normalize(array $v): array
    {
        $until = CarbonImmutable::parse($v['until'], 'UTC');
        if (! empty($v['ended_at'])) {
            $until = min($until, CarbonImmutable::parse($v['ended_at'], 'UTC'));
        }

        return ['node' => (string) ($v['node'] ?? '?'), 'started_at' => CarbonImmutable::parse($v['started_at'], 'UTC'), 'until' => $until, 'reason' => $v['reason'] ?? null, 'actor_name' => $v['actor_name'] ?? null];
    }
}
