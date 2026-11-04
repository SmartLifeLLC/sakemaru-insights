<?php

namespace Tests\Feature\Monitoring;

use App\Models\User;
use App\Monitoring\Services\CheckRegistry;
use App\Monitoring\Services\RunbookRegistry;
use App\Monitoring\Services\SiteRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 監視機能のテスト基底。専用の SQLite テストDB（database/testing.sqlite）を使い、RefreshDatabase は使わない。
 * 各テストの前に監視テーブルだけを delete で初期化する（AGENTS.md）。
 */
abstract class MonitoringTestCase extends TestCase
{
    protected static bool $schemaReady = false;

    protected function setUp(): void
    {
        $path = dirname(__DIR__, 3).'/database/testing.sqlite';
        if (getenv('MONITOR_TEST_MYSQL')) {
            // MySQL/MariaDB で同じテストを流す（本番互換の確認）。接続は phpunit 実行時の環境変数（DB_* / MONITOR_STORE_*）で与える
            parent::setUp();
            $this->commonSetUp();

            return;
        }
        putenv('DB_CONNECTION=sqlite');
        putenv('DB_DATABASE='.$path);
        putenv('DB_DRIVER_SAKEMARU=sqlite');
        putenv('DB_DATABASE_SAKEMARU='.$path);
        putenv('MONITOR_STORE_DRIVER=sqlite');
        putenv('MONITOR_SOURCE_DATABASE=');
        $_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'sqlite';
        $_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $path;
        $_ENV['DB_DRIVER_SAKEMARU'] = $_SERVER['DB_DRIVER_SAKEMARU'] = 'sqlite';
        $_ENV['DB_DATABASE_SAKEMARU'] = $_SERVER['DB_DATABASE_SAKEMARU'] = $path;
        if (! is_file($path)) {
            touch($path);
        }
        parent::setUp();
        $this->commonSetUp();
    }

    private function commonSetUp(): void
    {
        // 秘密値は config 構築時の写し（secret_values）が優先される。テストでは前のテストの環境変数が残らないよう写しを空にする
        config()->set('monitoring.secret_values', []);
        config()->set('monitoring.collector.state_dir', storage_path('framework/testing/monitor-state'));
        config()->set('monitoring.collector.spool_dir', storage_path('framework/testing/monitor-spool'));
        config()->set('monitoring.permissions', ['monitor.view' => ['*'], 'monitor.respond' => ['*'], 'monitor.manage' => ['*'], 'monitor.delivery_admin' => ['*']]);
        // メール・電話は経路ごとのテストで明示的に有効にする（v1 のテストは Slack だけを前提）
        config()->set('monitoring.mail.enabled', false);
        config()->set('monitoring.phone.enabled', false);
        foreach (['MAIL_HOST', 'MAIL_FROM_ADDRESS', 'TWILIO_ACCOUNT_SID', 'TWILIO_AUTH_TOKEN', 'TWILIO_FROM_NUMBER'] as $k) {
            putenv($k);
            unset($_ENV[$k], $_SERVER[$k]);
        }

        $this->ensureSchema();
        $this->cleanMonitorTables();
        app(SiteRegistry::class)->forget();
        app(\App\Monitoring\Services\FeatureRegistry::class)->forget();
        app(\App\Monitoring\Services\SecretStore::class)->forget();
        app(\App\Monitoring\Services\NotificationSettings::class)->forget();
    }

    protected function ensureSchema(): void
    {
        $store = Schema::connection('monitor_store');
        if (! $store->hasTable('monitor_sites')) {
            $migration = require base_path('database/migrations/2026_11_03_000001_create_ins_monitor_tables.php');
            $migration->up();
        }
        if (! $store->hasTable('monitor_features')) {
            $migration = require base_path('database/migrations/2026_11_04_000001_create_ins_monitor_v2_tables.php');
            $migration->up();
        }
        if (! $store->hasTable('monitor_advisories')) {
            $migration = require base_path('database/migrations/2026_11_05_000001_create_ins_monitor_advisories.php');
            $migration->up();
        }
        $auth = Schema::connection('sakemaru');
        if (! $auth->hasTable('client_settings')) {
            $auth->create('client_settings', function ($t) {
                $t->id();
                $t->date('system_date')->nullable();
            });
        }
        if (! $auth->hasTable('users')) {
            $auth->create('users', function ($t) {
                $t->id();
                $t->unsignedBigInteger('client_id')->nullable();
                $t->string('name');
                $t->string('kana_name')->nullable();
                $t->string('email')->unique();
                $t->string('code')->nullable();
                $t->string('password');
                $t->boolean('is_active')->default(1);
                $t->string('remember_token')->nullable();
                $t->timestamp('email_verified_at')->nullable();
            });
        }
    }

    protected function cleanMonitorTables(): void
    {
        $conn = DB::connection('monitor_store');
        foreach (['monitor_advisory_fetches', 'monitor_advisories', 'monitor_secrets', 'monitor_call_attempts', 'monitor_contacts', 'monitor_probe_results', 'monitor_server_metrics', 'monitor_targets', 'monitor_nodes', 'monitor_features', 'monitor_settings', 'monitor_recheck_requests', 'monitor_counters', 'monitor_audit_events', 'monitor_runbooks', 'monitor_notification_attempts', 'monitor_delivery_batches', 'monitor_notifications', 'monitor_incident_events', 'monitor_incidents', 'monitor_logs', 'monitor_check_attempts', 'monitor_check_runs', 'monitor_checks', 'monitor_collectors', 'monitor_sites'] as $t) {
            $conn->table($t)->delete();
        }
        if ($conn->getDriverName() === 'sqlite') {
            $conn->statement("DELETE FROM sqlite_sequence WHERE name LIKE 'ins_monitor_%'");
        } else {
            foreach (['monitor_incidents', 'monitor_notifications', 'monitor_logs', 'monitor_delivery_batches'] as $t) {
                $conn->statement('ALTER TABLE '.$conn->getTablePrefix().$t.' AUTO_INCREMENT = 1'); // 公開 ID（MON-000001）の検証を SQLite と揃える
            }
        }
    }

    /**
     * 業務DB（monitor_source）を同じ SQLite テストDBに向ける。業務テーブルは接頭辞なしで作るため監視テーブル（ins_）と衝突しない。
     */
    protected function useSourceStub(): void
    {
        config()->set('database.connections.monitor_source', getenv('MONITOR_TEST_MYSQL') ? [
            'driver' => 'mysql', 'host' => env('DB_HOST'), 'port' => env('DB_PORT'), 'database' => env('MONITOR_TEST_SOURCE_DATABASE', 'ins_src'),
            'username' => env('DB_USERNAME'), 'password' => env('DB_PASSWORD'), 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
        ] : [
            'driver' => 'sqlite', 'database' => dirname(__DIR__, 3).'/database/testing.sqlite', 'prefix' => '', 'foreign_key_constraints' => false,
        ]);
        DB::purge('monitor_source');
    }

    /** 業務テーブルのスタブを作り直す（毎テスト） */
    protected function sourceTable(string $table, \Closure $columns): void
    {
        $schema = Schema::connection('monitor_source');
        $schema->dropIfExists($table);
        $schema->create($table, $columns);
    }

    protected function sourceInsert(string $table, array $rows): void
    {
        foreach (array_is_list($rows) ? $rows : [$rows] as $row) {
            DB::connection('monitor_source')->table($table)->insert($row); // 行ごとに列が異なってよい
        }
    }

    protected function bootRegistries(): void
    {
        $sites = app(SiteRegistry::class);
        $sites->sync();
        app(CheckRegistry::class)->sync($sites);
        app(RunbookRegistry::class)->sync();
    }

    protected function user(string $email = 'ops@example.com'): User
    {
        $existing = User::query()->where('email', $email)->first();
        if ($existing) {
            return $existing;
        }

        return User::query()->create(['name' => '運用 太郎', 'email' => $email, 'password' => bcrypt('secret'), 'is_active' => 1]);
    }

    /** @return array<string,mixed> */
    protected function event(array $overrides = []): array
    {
        return array_merge([
            'schema_version' => 1,
            'event_id' => (string) \Illuminate\Support\Str::uuid(),
            'application_code' => 'TRADE',
            'node_code' => 'web1',
            'source_kind' => 'APPLICATION_LOG',
            'severity' => 'ERROR',
            'error_code' => 'QueryException',
            'occurred_at' => now('UTC')->toIso8601String(),
            'summary' => 'SQLSTATE[HY000] connection refused',
            'scope' => ['fp' => 'abc123'],
            'evidence' => ['sample' => 'stack'],
        ], $overrides);
    }
}
