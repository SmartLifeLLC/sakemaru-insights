<?php

namespace App\Monitoring\Support;

/**
 * サーバー側で描く SVG チャート（外部 CDN・JS ライブラリを使わない。v2 第6.3）。
 * 色は CSS 変数（テーマ追随）。各点に <title> を付け、ホバーで値が出る。
 */
final class Svg
{
    private static function fmt(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
    }

    private static function niceMax(float $max): float
    {
        if ($max <= 0) {
            return 1;
        }
        $p = 10 ** floor(log10($max));
        foreach ([1, 2, 2.5, 5, 10] as $m) {
            if ($max <= $m * $p) {
                return $m * $p;
            }
        }

        return 10 * $p;
    }

    /**
     * 折れ線（今回 実線 / 前期間 破線）。
     *
     * @param  array<int,float|int|null>  $cur
     * @param  array<int,float|int|null>|null  $prev
     * @param  string[]  $labels  x 軸ラベル（点と同数）
     * @param  string[]  $titles  点ごとのツールチップ
     */
    public static function line(array $cur, ?array $prev, array $labels, array $titles = [], string $unit = '件', int $w = 680, int $h = 210, ?float $fixedMax = null, string $curClass = 'line-cur'): string
    {
        $n = max(1, count($cur));
        $padL = 38;
        $padR = 10;
        $padT = 12;
        $padB = 24;
        $all = array_filter(array_merge($cur, $prev ?? []), fn ($v) => $v !== null);
        $max = $fixedMax ?? self::niceMax($all ? max($all) : 0);
        $x = fn (int $i) => $padL + ($n === 1 ? 0 : $i * ($w - $padL - $padR) / ($n - 1));
        $y = fn ($v) => $padT + ($h - $padT - $padB) * (1 - min(1, max(0, (float) $v / $max)));
        $out = '<svg viewBox="0 0 '.$w.' '.$h.'" preserveAspectRatio="none" role="img" aria-label="推移グラフ">';
        for ($g = 0; $g <= 4; $g++) {
            $gy = $padT + ($h - $padT - $padB) * $g / 4;
            $out .= '<line class="chart-grid" x1="'.$padL.'" x2="'.($w - $padR).'" y1="'.$gy.'" y2="'.$gy.'"/>';
            $out .= '<text class="chart-axis" x="'.($padL - 6).'" y="'.($gy + 3).'" text-anchor="end">'.self::fmt($max * (1 - $g / 4)).'</text>';
        }
        $step = max(1, (int) ceil($n / 8));
        foreach ($labels as $i => $l) {
            if ($i % $step === 0 || $i === $n - 1) {
                $out .= '<text class="chart-axis" x="'.$x($i).'" y="'.($h - 6).'" text-anchor="middle">'.e($l).'</text>';
            }
        }
        $path = function (array $vals) use ($x, $y) {
            $d = '';
            $pen = false;
            foreach (array_values($vals) as $i => $v) {
                if ($v === null) {
                    $pen = false;

                    continue;
                }
                $d .= ($pen ? 'L' : 'M').round($x($i), 1).' '.round($y($v), 1).' ';
                $pen = true;
            }

            return trim($d);
        };
        if ($prev) {
            $out .= '<path class="line-prev" d="'.$path($prev).'"/>';
        }
        $d = $path($cur);
        if ($d !== '' && $curClass === 'line-cur') {
            $vals = array_values($cur);
            $last = max(array_keys(array_filter($vals, fn ($v) => $v !== null)) ?: [0]);
            $first = min(array_keys(array_filter($vals, fn ($v) => $v !== null)) ?: [0]);
            $out .= '<path class="area-cur" d="'.$d.' L'.round($x($last), 1).' '.($h - $padB).' L'.round($x($first), 1).' '.($h - $padB).' Z"/>';
        }
        $out .= '<path class="'.$curClass.'" d="'.$d.'"/>';
        $bw = ($w - $padL - $padR) / $n;
        foreach (array_values($cur) as $i => $v) {
            $t = $titles[$i] ?? (($labels[$i] ?? '').' '.($v === null ? '—' : self::fmt((float) $v).$unit).($prev && isset($prev[$i]) ? ' / 前期間 '.self::fmt((float) $prev[$i]).$unit : ''));
            $out .= '<rect class="chart-hit" x="'.round($x($i) - $bw / 2, 1).'" y="'.$padT.'" width="'.round($bw, 1).'" height="'.($h - $padT - $padB).'"><title>'.e($t).'</title></rect>';
        }

        return $out.'</svg>';
    }

    /** 複数系列（ノード詳細: CPU・メモリ等）。 @param array<string,array{values:array,class:string,label:string}> $series */
    public static function multi(array $series, array $labels, float $max = 100, string $unit = '%', int $w = 680, int $h = 200): string
    {
        $first = true;
        $out = '';
        foreach ($series as $s) {
            $svg = self::line($s['values'], null, $labels, [], $unit, $w, $h, $max, $s['class']);
            if ($first) {
                $out = substr($svg, 0, -6); // </svg> を外して重ねる
                $first = false;
            } else {
                if (preg_match('/<path class="'.preg_quote($s['class'], '/').'" d="[^"]*"\/>/', $svg, $m)) {
                    $out .= $m[0];
                }
            }
        }

        return $out === '' ? '' : $out.'</svg>';
    }

    /** @param array<int,float|int|null> $values */
    public static function spark(array $values, int $w = 86, int $h = 28, string $class = 'spark-line'): string
    {
        $vals = array_values(array_map(fn ($v) => $v === null ? 0.0 : (float) $v, $values));
        $n = count($vals);
        if ($n < 2) {
            return '<svg class="kpi-spark" viewBox="0 0 '.$w.' '.$h.'" aria-hidden="true"></svg>';
        }
        $max = max($vals) ?: 1;
        $min = min($vals);
        $range = ($max - $min) ?: 1;
        $pts = [];
        foreach ($vals as $i => $v) {
            $pts[] = round($i * $w / ($n - 1), 1).' '.round($h - 2 - ($v - $min) / $range * ($h - 4), 1);
        }

        return '<svg class="kpi-spark" viewBox="0 0 '.$w.' '.$h.'" preserveAspectRatio="none" aria-hidden="true"><path class="spark-area" d="M0 '.$h.' L'.implode(' L', $pts).' L'.$w.' '.$h.' Z"/><path class="'.$class.'" d="M'.implode(' L', $pts).'"/></svg>';
    }

    /** @param array<int,array{label:string,value:int|float,color:string}> $segments */
    public static function donut(array $segments, string $center = '', string $sub = ''): string
    {
        $total = array_sum(array_column($segments, 'value'));
        $r = 52;
        $c = 2 * M_PI * $r;
        $out = '<svg viewBox="0 0 150 150" role="img" aria-label="判定結果の内訳"><circle cx="75" cy="75" r="'.$r.'" fill="none" stroke="var(--border-soft)" stroke-width="16"/>';
        $offset = 0.0;
        foreach ($segments as $s) {
            if ($total <= 0 || $s['value'] <= 0) {
                continue;
            }
            $len = $s['value'] / $total * $c;
            $out .= '<circle cx="75" cy="75" r="'.$r.'" fill="none" stroke="'.$s['color'].'" stroke-width="16" stroke-dasharray="'.round($len, 2).' '.round($c - $len, 2).'" stroke-dashoffset="'.round(-$offset, 2).'" transform="rotate(-90 75 75)"><title>'.e($s['label'].' '.number_format($s['value'])).'</title></circle>';
            $offset += $len;
        }
        $out .= '<text x="75" y="74" text-anchor="middle" class="gauge-val" style="font-size:20px">'.e($center).'</text>';
        $out .= '<text x="75" y="92" text-anchor="middle" class="chart-axis">'.e($sub).'</text>';

        return $out.'</svg>';
    }

    /** 半円ゲージ。level: ok / warning / error */
    public static function gauge(?float $pct, string $level = 'ok'): string
    {
        $color = match ($level) {
            'error', 'critical' => 'var(--red)',
            'warning' => 'var(--amber)',
            default => 'var(--green)',
        };
        $r = 30;
        $len = M_PI * $r;
        $val = $pct === null ? 0 : max(0, min(100, $pct)) / 100 * $len;

        return '<svg viewBox="0 0 80 50" role="img" aria-label="'.($pct === null ? '—' : self::fmt($pct).'%').'">'
            .'<path class="gauge-track" d="M10 42 A30 30 0 0 1 70 42" fill="none" stroke-width="7" stroke-linecap="round"/>'
            .'<path d="M10 42 A30 30 0 0 1 70 42" fill="none" stroke="'.$color.'" stroke-width="7" stroke-linecap="round" stroke-dasharray="'.round($val, 2).' '.round($len, 2).'"/>'
            .'<text x="40" y="41" text-anchor="middle" class="gauge-val">'.($pct === null ? '—' : self::fmt($pct)).'</text></svg>';
    }
}
