<?php

namespace App\Console\Commands\Monitoring;

use App\Models\Monitoring\Target;
use App\Monitoring\Enums\TargetKind;
use App\Monitoring\Services\SiteRegistry;
use Illuminate\Console\Command;

/**
 * 配置設定（config/monitoring.php collectors）のアプリログ収集器を「監視対象（APPLICATION_LOG）」台帳へ写す（v2 P10）。
 * ログパスは配置設定が正（画面からは登録しない）。台帳は機能の推奨値検出と画面表示のための写し。冪等。
 */
class SyncTargetsCommand extends Command
{
    protected $signature = 'monitor:sync-targets';

    protected $description = '運用監視: 収集器設定のログパスを監視対象台帳（APPLICATION_LOG）へ同期する';

    public function handle(SiteRegistry $sites): int
    {
        $sites->sync();
        $seen = [];
        foreach ((array) config('monitoring.collectors', []) as $c) {
            $site = $sites->site((string) ($c['application_code'] ?? ''));
            if (! $site) {
                $this->warn('不明なサービス: '.($c['application_code'] ?? '?'));

                continue;
            }
            $uuid = (string) ($c['collector_uuid'] ?? '');
            $t = Target::query()->where('kind', TargetKind::APPLICATION_LOG->value)->get()->first(fn ($x) => ($x->params_json['collector_uuid'] ?? null) === $uuid)
                ?? new Target(['kind' => TargetKind::APPLICATION_LOG->value, 'enabled' => true, 'version' => 1]);
            $t->site_id = $site->id;
            $t->display_name = mb_substr(($c['node_code'] ?? '?').' のアプリログ', 0, 120);
            $t->params_json = ['collector_uuid' => $uuid, 'node_code' => $c['node_code'] ?? null, 'log_files' => array_values((array) ($c['log_files'] ?? [])), 'source' => 'config'];
            $t->updated_name = 'CLI sync-targets';
            $t->reason = '配置設定から同期';
            $t->save();
            $seen[] = $t->id;
            $this->line(sprintf('%s@%s %d ファイル', $site->application_code, $c['node_code'] ?? '?', count((array) ($c['log_files'] ?? []))));
        }
        // 配置設定から消えた収集器は無効化（削除はしない）
        $off = Target::query()->where('kind', TargetKind::APPLICATION_LOG->value)->whereNotIn('id', $seen ?: [0])->where('enabled', true)->update(['enabled' => false, 'reason' => '配置設定から削除']);
        $this->info('同期しました（'.count($seen).' 件、無効化 '.$off.' 件）。');

        return self::SUCCESS;
    }
}
