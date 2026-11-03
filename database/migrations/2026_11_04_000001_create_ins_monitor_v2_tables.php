<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 運用監視 v2（仕様書 v2 第4章・第5章・第7章）。
 * 機能 ON/OFF、ノード台帳、監視対象、資源 5 分集約、HTTP プローブ結果、電話連絡先・発信履歴、暗号化設定。
 * すべて monitor_store 接続（prefix ins_）。業務テーブルへの FK は作らない。
 */
return new class extends Migration
{
    protected $connection = 'monitor_store';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        $schema->create('monitor_features', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('site_id');
            $t->string('feature_key', 64);
            $t->string('display_name', 120);
            $t->boolean('enabled')->default(true);
            $t->boolean('recommended_enabled')->nullable();
            $t->string('recommended_reason', 255)->nullable();
            $t->dateTime('recommended_checked_at', 6)->nullable();
            $t->unsignedBigInteger('updated_user_id')->nullable();
            $t->string('updated_name', 100)->nullable();
            $t->string('reason', 1000)->nullable();
            $t->unsignedInteger('sort_order')->default(100);
            $t->dateTime('created_at', 6)->nullable();
            $t->dateTime('updated_at', 6)->nullable();
            $t->unique(['site_id', 'feature_key'], 'mfeat_site_key_uq');
        });

        $schema->table('monitor_sites', function (Blueprint $t) {
            $t->string('enabled_reason', 1000)->nullable();
            $t->unsignedBigInteger('updated_user_id')->nullable();
            $t->string('updated_name', 100)->nullable();
        });

        $schema->table('monitor_checks', function (Blueprint $t) {
            $t->string('feature_key', 64)->nullable();
            $t->json('rule_json')->nullable();
            $t->unsignedInteger('priority')->default(100);
            $t->unsignedBigInteger('updated_user_id')->nullable();
            $t->string('updated_name', 100)->nullable();
            $t->string('reason', 1000)->nullable();
        });

        $schema->table('monitor_notifications', function (Blueprint $t) {
            // slack / mail / phone。route_key は経路設定のキー
            $t->string('channel', 16)->default('slack');
        });

        $schema->create('monitor_nodes', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('node_code', 64)->unique();
            $t->string('display_name', 120);
            $t->string('role', 16)->default('web');
            $t->boolean('enabled')->default(true);
            $t->json('os_info_json')->nullable();
            $t->json('thresholds_json')->nullable();
            $t->dateTime('last_heartbeat_at', 6)->nullable();
            $t->dateTime('last_metrics_at', 6)->nullable();
            $t->json('latest_json')->nullable(); // 直近 30 秒値（ノードカード用。収集器が 30 秒ごとに更新）
            $t->string('registered_from', 16)->default('env');
            $t->unsignedBigInteger('updated_user_id')->nullable();
            $t->string('updated_name', 100)->nullable();
            $t->string('reason', 1000)->nullable();
            $t->dateTime('created_at', 6)->nullable();
            $t->dateTime('updated_at', 6)->nullable();
        });

        $schema->create('monitor_targets', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('kind', 24); // APPLICATION_LOG / HTTP_PROBE / PROCESS
            $t->unsignedBigInteger('site_id')->nullable();
            $t->unsignedBigInteger('node_id')->nullable();
            $t->string('display_name', 120);
            $t->json('params_json');
            $t->json('thresholds_json')->nullable();
            $t->string('route_key', 64)->nullable();
            $t->boolean('enabled')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->unsignedBigInteger('updated_user_id')->nullable();
            $t->string('updated_name', 100)->nullable();
            $t->string('reason', 1000)->nullable();
            $t->dateTime('created_at', 6)->nullable();
            $t->dateTime('updated_at', 6)->nullable();
            $t->index(['kind', 'enabled', 'id'], 'mtgt_kind_idx');
            $t->index(['node_id', 'kind'], 'mtgt_node_idx');
        });

        $schema->create('monitor_server_metrics', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('node_id');
            $t->dateTime('bucket_start', 6); // 5 分境界（UTC）
            $t->unsignedSmallInteger('sample_count')->default(0);
            $t->float('cpu_avg')->nullable();
            $t->float('cpu_max')->nullable();
            $t->float('iowait_avg')->nullable();
            $t->float('iowait_max')->nullable();
            $t->float('steal_avg')->nullable();
            $t->float('load1_avg')->nullable();
            $t->float('load1_max')->nullable();
            $t->float('mem_used_pct_avg')->nullable();
            $t->float('mem_used_pct_max')->nullable();
            $t->float('swap_used_pct_max')->nullable();
            $t->float('disk_used_pct_max')->nullable(); // 全マウントの最大
            $t->float('psi_cpu_avg')->nullable();
            $t->float('psi_io_avg')->nullable();
            $t->float('psi_mem_avg')->nullable();
            $t->json('disk_json')->nullable();    // マウント別 used_pct / free_gb / inode_pct（last）
            $t->json('io_json')->nullable();      // デバイス別 r/w IOPS・MB/s・util（avg/max）
            $t->json('net_json')->nullable();     // IF 別 rx/tx bytes/s（avg/max）
            $t->json('extra_json')->nullable();   // 接続数・プロセス生存
            $t->dateTime('expires_at', 6)->nullable();
            $t->unique(['node_id', 'bucket_start'], 'msm_node_bucket_uq');
            $t->index(['expires_at', 'id'], 'msm_exp_idx');
        });

        $schema->create('monitor_probe_results', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('target_id');
            $t->unsignedBigInteger('site_id')->nullable();
            $t->dateTime('checked_at', 6);
            $t->unsignedSmallInteger('http_status')->nullable();
            $t->unsignedInteger('duration_ms')->nullable();
            $t->boolean('ok');
            $t->string('error_code', 64)->nullable();
            $t->dateTime('expires_at', 6)->nullable();
            $t->index(['target_id', 'id'], 'mpr_target_idx');
            $t->index(['site_id', 'checked_at'], 'mpr_site_time_idx');
            $t->index(['expires_at', 'id'], 'mpr_exp_idx');
        });

        $schema->create('monitor_contacts', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name', 100);
            $t->string('phone_e164', 20);
            $t->unsignedInteger('order_no')->default(100);
            $t->boolean('enabled')->default(true);
            $t->string('active_from', 5)->nullable(); // HH:MM（任意）
            $t->string('active_to', 5)->nullable();
            $t->unsignedBigInteger('updated_user_id')->nullable();
            $t->string('updated_name', 100)->nullable();
            $t->string('reason', 1000)->nullable();
            $t->dateTime('created_at', 6)->nullable();
            $t->dateTime('updated_at', 6)->nullable();
            $t->index(['enabled', 'order_no'], 'mcon_order_idx');
        });

        $schema->create('monitor_call_attempts', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('notification_id');
            $t->unsignedBigInteger('contact_id')->nullable();
            $t->unsignedInteger('round_no')->default(1);
            $t->unsignedInteger('attempt_no');
            $t->string('token_hash', 64)->unique();
            $t->dateTime('token_expires_at', 6);
            $t->string('call_sid', 64)->nullable();
            $t->dateTime('scheduled_at', 6);
            $t->dateTime('started_at', 6)->nullable();
            $t->dateTime('finished_at', 6)->nullable();
            // scheduled / queued / ringing / in-progress / completed / no-answer / busy / failed / acknowledged / cancelled
            $t->string('status', 16)->default('scheduled');
            $t->string('digits', 8)->nullable();
            $t->unsignedInteger('duration_sec')->nullable();
            $t->string('sanitized_error', 255)->nullable();
            $t->dateTime('expires_at', 6)->nullable();
            $t->index(['notification_id', 'id'], 'mcall_ntf_idx');
            $t->index(['status', 'scheduled_at'], 'mcall_status_idx');
            $t->index(['expires_at', 'id'], 'mcall_exp_idx');
        });

        $schema->create('monitor_secrets', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('secret_key', 64)->unique();
            $t->text('value_encrypted');
            $t->unsignedBigInteger('updated_user_id')->nullable();
            $t->string('updated_name', 100)->nullable();
            $t->dateTime('updated_at', 6)->nullable();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        foreach (['monitor_secrets', 'monitor_call_attempts', 'monitor_contacts', 'monitor_probe_results', 'monitor_server_metrics', 'monitor_targets', 'monitor_nodes', 'monitor_features'] as $t) {
            $schema->dropIfExists($t);
        }
        $schema->table('monitor_notifications', fn (Blueprint $t) => $t->dropColumn('channel'));
        $schema->table('monitor_checks', fn (Blueprint $t) => $t->dropColumn(['feature_key', 'rule_json', 'priority', 'updated_user_id', 'updated_name', 'reason']));
        $schema->table('monitor_sites', fn (Blueprint $t) => $t->dropColumn(['enabled_reason', 'updated_user_id', 'updated_name']));
    }
};
