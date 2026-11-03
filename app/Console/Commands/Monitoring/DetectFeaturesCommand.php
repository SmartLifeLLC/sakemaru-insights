<?php

namespace App\Console\Commands\Monitoring;

use App\Monitoring\Services\CheckRegistry;
use App\Monitoring\Services\FeatureRegistry;
use App\Monitoring\Services\SiteRegistry;
use App\Monitoring\Services\SourceReader;
use Illuminate\Console\Command;

/**
 * 機能の推奨値（使用あり/なし）を業務DBから検出する（仕様書 v2 4.4）。設定値は変えない。
 * --apply を付けた場合だけ、推奨値を設定へ反映する（顧客追加時の初期設定用。監査に「CLI」として残す）。
 */
class DetectFeaturesCommand extends Command
{
    protected $signature = 'monitor:detect-features {--apply : 推奨値を設定へ反映する（理由は自動記入）} {--reason= : --apply 時の理由}';

    protected $description = '運用監視: 顧客が使っている機能を業務DBから検出し、推奨値として表示する';

    public function handle(SiteRegistry $sites, CheckRegistry $checks, FeatureRegistry $features): int
    {
        $sites->sync();
        $checks->sync($sites);
        $result = $features->detect(SourceReader::fromConfig());
        $rows = [];
        foreach ($result as $key => $r) {
            $f = collect($features->map())->first(fn ($x) => $x->feature_key === $key);
            $rows[] = [$key, $f?->display_name, $f?->enabled ? 'ON' : 'OFF', $r['recommended'] === null ? '未判定' : ($r['recommended'] ? '推奨ON' : '推奨OFF'), mb_substr($r['reason'], 0, 60)];
            if ($this->option('apply') && $f && $r['recommended'] !== null && $f->enabled !== $r['recommended']) {
                $features->setEnabled($f, $r['recommended'], ['id' => null, 'name' => 'CLI detect-features'], (string) ($this->option('reason') ?: '推奨値を反映（'.$r['reason'].'）'));
                $rows[count($rows) - 1][2] = ($r['recommended'] ? 'ON' : 'OFF').' ← 反映';
            }
        }
        $this->table(['機能', '名称', '設定', '推奨', '根拠'], $rows);
        if ($this->option('apply')) {
            $features->recomputeCoverage();
        }

        return self::SUCCESS;
    }
}
