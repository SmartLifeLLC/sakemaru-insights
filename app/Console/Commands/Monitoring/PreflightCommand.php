<?php

namespace App\Console\Commands\Monitoring;

use App\Monitoring\Services\CheckRegistry;
use App\Monitoring\Services\RunbookRegistry;
use App\Monitoring\Services\SiteRegistry;
use App\Monitoring\Services\SourceReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 事前検証（仕様書 第23章）: 接続分離・読取り権限・サイト台帳・通知経路を確認し、秘密値を出さずに報告する。
 */
class PreflightCommand extends Command
{
    protected $signature = 'monitor:preflight';

    protected $description = '運用監視: 接続・権限・設定の事前検証（本番 migration・配置の前に実行）';

    public function handle(SiteRegistry $sites, CheckRegistry $checks, RunbookRegistry $runbooks): int
    {
        $ok = true;
        $this->info('deployment: '.$sites->deploymentId().' / '.$sites->clientName().' / '.$sites->environmentLabel().' / node='.$sites->nodeCode().' / owner='.config('monitoring.deployment.check_owner_node'));

        // monitor_store
        try {
            $store = DB::connection('monitor_store');
            $store->select('SELECT 1');
            $this->line('monitor_store: OK ('.$store->getDriverName().' db='.$store->getDatabaseName().' prefix='.$store->getTablePrefix().')');
            if (! \Illuminate\Support\Facades\Schema::connection('monitor_store')->hasTable('monitor_sites')) {
                $this->warn('monitor_store: ins_monitor_* テーブルがありません。php artisan migrate --database=monitor_store を実行してください。');
                $ok = false;
            }
        } catch (\Throwable $e) {
            $this->error('monitor_store: NG '.get_class($e));
            $ok = false;
        }

        // monitor_source: 接続・DB 同一性・DML 拒否
        $reader = SourceReader::fromConfig();
        if (! $reader->isConfigured()) {
            $this->warn('monitor_source: 未設定（MONITOR_SOURCE_DATABASE）。業務照合チェックは UNKNOWN になります。');
        } else {
            try {
                $src = DB::connection('monitor_source');
                $src->select('SELECT 1');
                $this->line('monitor_source: OK (db='.$src->getDatabaseName().' user='.config('database.connections.monitor_source.username').')');
                $storeDb = (string) config('database.connections.monitor_store.database');
                if ($storeDb !== $src->getDatabaseName()) {
                    $this->warn("monitor_source と monitor_store の DB 名が異なります（{$src->getDatabaseName()} / {$storeDb}）。同じ顧客DBであることを確認してください。");
                }
                if ($src->getDriverName() === 'mysql') {
                    $grants = collect($src->select('SHOW GRANTS'))->map(fn ($r) => implode(' ', (array) $r))->implode("\n");
                    $hasWrite = preg_match('/\b(ALL PRIVILEGES|INSERT|UPDATE|DELETE|DROP|ALTER|CREATE)\b/i', $grants);
                    $this->line('monitor_source grants: '.($hasWrite ? 'WARNING 書込み権限を含む（専用 SELECT ユーザーを推奨）' : 'SELECT 専用 OK'));
                }
            } catch (\Throwable $e) {
                $this->error('monitor_source: NG '.get_class($e));
                $ok = false;
            }
        }

        // サイト・チェック
        try {
            $sites->sync();
            $checks->sync($sites);
            $runbooks->sync();
            foreach ($sites->all() as $s) {
                $this->line(sprintf('site %-9s %-28s coverage=%s', $s->application_code, $s->display_name, $s->coverage->value));
            }
            foreach ($checks->all() as $c) {
                $d = $c->definition();
                $this->line(sprintf('check %-7s %-26s v%d interval=%ds %s', $d->applicationCode, $d->key, $d->version, $d->intervalSeconds, $d->implemented ? '' : '[未実装: '.$d->implementationNote.']'));
            }
        } catch (\Throwable $e) {
            $this->error('registry sync: NG '.get_class($e).' '.$e->getMessage());
            $ok = false;
        }

        // v2 テーブル・機能・ノード・対象（V-133）
        try {
            $schema = \Illuminate\Support\Facades\Schema::connection('monitor_store');
            foreach (['monitor_features', 'monitor_nodes', 'monitor_targets', 'monitor_server_metrics', 'monitor_probe_results', 'monitor_contacts', 'monitor_call_attempts', 'monitor_secrets'] as $t) {
                if (! $schema->hasTable($t)) {
                    $this->warn("v2 テーブル {$t} がありません（2026_11_04 の migration が未実行）。");
                    $ok = false;
                }
            }
            $features = \App\Models\Monitoring\Feature::query()->get();
            $this->line(sprintf('features: %d（有効 %d / 要確認 %d）', $features->count(), $features->where('enabled', true)->count(), $features->filter(fn ($f) => $f->needsReview())->count()));
            if (\App\Models\Monitoring\Node::query()->count() > 1 && ! config('monitoring.deployment.check_owner_explicit')) {
                $this->error('複数ノードがあるのに MONITOR_CHECK_OWNER_NODE が未設定です。全ノードが業務照合を二重実行します。owner を 1 台だけ指定してください。');
                $ok = false;
            }
            $this->line('nodes: '.\App\Models\Monitoring\Node::query()->count().'（このサーバー: '.config('monitoring.resources.node.code').' / '.config('monitoring.resources.node.role').'）');
            $this->line('http probes: '.\App\Models\Monitoring\Target::query()->where('kind', 'HTTP_PROBE')->count().'（許可ホスト '.count((array) config('monitoring.probe.allowed_hosts')).' 件）');
            $this->line('metrics secret ('.config('monitoring.resources.secret_env').'): '.(\App\Monitoring\Support\Env::get((string) config('monitoring.resources.secret_env')) ? 'set' : (config('monitoring.collector.ingest_url') ? 'MISSING（別サーバーから資源データを送れません）' : '不要（同一ホスト直接保存）')));
            $this->line('metrics dir: '.config('monitoring.resources.data_dir').' '.(is_writable(dirname((string) config('monitoring.resources.data_dir'))) ? 'writable' : 'NOT WRITABLE'));
        } catch (\Throwable $e) {
            $this->warn('v2 確認: '.get_class($e));
        }

        // 通知経路（秘密値は出さない。画面の設定 > .env）
        $secrets = app(\App\Monitoring\Services\SecretStore::class);
        $this->line('mail: '.(config('monitoring.mail.enabled') ? 'enabled' : 'disabled').' smtp='.($secrets->smtpConfigured() ? 'set('.$secrets->source('smtp.host').')' : 'MISSING（送信をスキップ）').' to='.implode(',', (array) app(\App\Monitoring\Services\NotificationSettings::class)->mail()['recipients']));
        $ns = app(\App\Monitoring\Services\NotificationSettings::class);
        $this->line('phone: '.(config('monitoring.phone.enabled') ? 'enabled' : 'disabled').' twilio='.($secrets->twilioConfigured() ? 'set' : 'MISSING').' allow_env='.($ns->phoneAllowedHere() ? 'match' : 'NOT MATCH（発信しない）').' contacts='.\App\Models\Monitoring\Contact::query()->where('enabled', true)->count().' public_url='.((config('monitoring.phone.public_base_url') ?: config('app.url')) ?: 'MISSING'));
        foreach ((array) config('monitoring.notification.routes') as $key => $r) {
            $set = $key === config('monitoring.notification.default_route') ? $secrets->get('slack.webhook_url') !== null : (bool) env($r['webhook_env'] ?? '');
            $this->line(sprintf('route %-14s display=%s webhook_env=%s %s', $key, $r['display_name'] ?? '-', $r['webhook_env'] ?? '-', $set ? 'SET' : 'MISSING'));
            if (! $set) {
                $ok = false;
            }
        }
        $this->line('fallback_contact: '.(config('monitoring.notification.fallback_contact') ? 'set' : 'MISSING（本番移行の未完了条件）'));
        $this->line('external_heartbeat: '.(config('monitoring.notification.external_heartbeat_url') ? 'set' : 'MISSING（サーバー全停止は検知不可）'));

        // 収集器
        $cols = (array) config('monitoring.collectors');
        $this->line('collectors: '.count($cols));
        $remote = (bool) config('monitoring.collector.ingest_url');
        foreach ($cols as $c) {
            $secret = \App\Monitoring\Support\Env::get($c['secret_env'] ?? null) !== null;
            $secretLabel = $remote ? ($secret ? 'set' : 'MISSING') : '不要（同一ホスト直接取込）';
            foreach ((array) ($c['log_files'] ?? []) as $pattern) {
                $files = \App\Monitoring\Collectors\FileLogCollector::expandLogFiles([$pattern]);
                if ($files === []) {
                    $this->line(sprintf('  %s@%s %s readable=NO（該当ファイルなし・直近 2 日に更新なし） secret=%s', $c['application_code'], $c['node_code'], $pattern, $secretLabel));
                    $ok = false;
                }
                foreach ($files as $f) {
                    $readable = is_readable($f);
                    $ok = $ok && $readable;
                    $this->line(sprintf('  %s@%s %s readable=%s secret=%s', $c['application_code'], $c['node_code'], $f, $readable ? 'yes' : 'NO', $secretLabel));
                }
            }
        }
        if (! $sites->isProduction() && str_contains(strtolower((string) config('monitoring.notification.routes.error_channel.display_name', '')), 'prod')) {
            $this->warn('非本番環境で本番らしいチャンネル名が設定されています。STG/LOCAL から本番チャンネルへ送らないでください。');
        }
        $this->line($ok ? 'preflight: OK' : 'preflight: 未完了項目あり');

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
