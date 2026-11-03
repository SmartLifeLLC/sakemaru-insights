<?php

namespace App\Monitoring\Checks;

use App\Monitoring\Services\CheckContext;
use Carbon\CarbonImmutable;

/**
 * 業務チェック共通。業務DBの日時はアプリ時刻（Asia/Tokyo、タイムゾーンなし）で保存されているため、比較は JST 文字列で行う。
 */
abstract class BusinessCheck extends AbstractCheck
{
    protected const DOW = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    protected function jst(CarbonImmutable $t): string
    {
        return $t->setTimezone('Asia/Tokyo')->format('Y-m-d H:i:s');
    }

    /**
     * 締切（予定時刻＋猶予）を直近 lookback 時間内に過ぎた予定枠を返す。
     *
     * @param  array<int|string,mixed>  $slots  ['02:00', '07:00'] は毎日。['mon' => '13:30', 'sun' => ['23:35']] は曜日別。
     *                                          ['daily' => [...], 'mon' => [...]] の併用も可。
     * @return array<int,array{date:string,time:string,start:CarbonImmutable,deadline:CarbonImmutable}>
     */
    protected function dueSlots(CheckContext $ctx, array $slots, int $graceMinutes, int $lookbackHours = 26): array
    {
        $now = $ctx->nowLocal();
        $byDow = $this->normalizeSlots($slots);
        $out = [];
        for ($d = 2; $d >= 0; $d--) {
            $day = $now->subDays($d)->startOfDay();
            $dow = self::DOW[$day->dayOfWeek];
            foreach (array_unique(array_merge($byDow['daily'] ?? [], $byDow[$dow] ?? [])) as $time) {
                $start = CarbonImmutable::parse($day->toDateString().' '.$time, 'Asia/Tokyo');
                $deadline = $start->addMinutes($graceMinutes);
                if ($deadline->lessThanOrEqualTo($now) && $deadline->greaterThan($now->subHours($lookbackHours))) {
                    $out[] = ['date' => $day->toDateString(), 'time' => $time, 'start' => $start, 'deadline' => $deadline];
                }
            }
        }
        usort($out, fn ($a, $b) => $a['start'] <=> $b['start']);

        return $out;
    }

    /** 次に締切を迎える予定枠（NOT_DUE 表示用） */
    protected function nextSlot(CheckContext $ctx, array $slots, int $graceMinutes): ?array
    {
        $now = $ctx->nowLocal();
        $byDow = $this->normalizeSlots($slots);
        for ($d = 0; $d <= 7; $d++) {
            $day = $now->addDays($d)->startOfDay();
            $times = array_unique(array_merge($byDow['daily'] ?? [], $byDow[self::DOW[$day->dayOfWeek]] ?? []));
            sort($times);
            foreach ($times as $time) {
                $start = CarbonImmutable::parse($day->toDateString().' '.$time, 'Asia/Tokyo');
                if ($start->addMinutes($graceMinutes)->greaterThan($now)) {
                    return ['date' => $day->toDateString(), 'time' => $time, 'start' => $start, 'deadline' => $start->addMinutes($graceMinutes)];
                }
            }
        }

        return null;
    }

    /** @return array<string,string[]> */
    private function normalizeSlots(array $slots): array
    {
        if (array_is_list($slots)) {
            return ['daily' => array_map('strval', $slots)];
        }
        $out = [];
        foreach ($slots as $k => $v) {
            $out[strtolower((string) $k)] = array_map('strval', (array) $v);
        }

        return $out;
    }

    /** 例外文字列から「クラス: 先頭行」を取り出し、数値・ID を伏せた集約キーにする */
    protected function exceptionKey(?string $exception): array
    {
        $first = trim(strtok((string) $exception, "\n") ?: '');
        $class = preg_match('/^([A-Za-z0-9_\\\\]+(?:Exception|Error))\b/', $first, $m) ? $m[1] : 'UnknownException';
        $msg = trim(preg_replace('/^[A-Za-z0-9_\\\\]+(?:Exception|Error):?\s*/', '', $first));
        $norm = \App\Monitoring\Support\Fingerprint::normalizeExceptionMessage(preg_replace('/ in \/.*$/', '', $msg));

        return ['class' => class_basename(str_replace('\\', '/', $class)), 'message' => mb_substr($msg, 0, 200), 'key' => substr(hash('sha256', $class.'|'.$norm), 0, 16)];
    }
}
