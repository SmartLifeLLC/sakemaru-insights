<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 運用監視テーブル（仕様書 第9章）。
 * - すべて monitor_store 接続（prefix ins_）に作成する。コード上のテーブル名は monitor_* とし、実テーブルは ins_monitor_*。
 * - 業務テーブルへの FK・JOIN・CASCADE は作らない。参照整合性はサービスとテストで保証する。
 * - 時刻は UTC DATETIME(6)。tenant/client 列は持たない（1 DB = 1 client）。
 */
return new class extends Migration
{
    protected $connection = 'monitor_store';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        $schema->create('monitor_sites', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('application_code', 32)->unique();
            $t->string('display_name', 100);
            $t->boolean('enabled')->default(true);
            $t->string('coverage', 20)->default('NOT_CONFIGURED');
            $t->json('coverage_json')->nullable();
            $t->string('owner_group', 64)->nullable();
            $t->unsignedInteger('config_version')->default(1);
            $t->unsignedInteger('sort_order')->default(100);
            $t->dateTime('created_at', 6)->nullable();
            $t->dateTime('updated_at', 6)->nullable();
        });

        $schema->create('monitor_collectors', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->uuid('collector_uuid')->unique();
            $t->unsignedBigInteger('site_id');
            $t->string('node_code', 64);
            $t->string('credential_ref', 128)->nullable();
            $t->dateTime('last_heartbeat_at', 6)->nullable();
            $t->unsignedBigInteger('last_sequence')->default(0);
            $t->string('status', 20)->default('UNKNOWN');
            $t->string('version', 32)->nullable();
            $t->json('state_json')->nullable();
            $t->dateTime('created_at', 6)->nullable();
            $t->dateTime('updated_at', 6)->nullable();
            $t->unique(['site_id', 'node_code'], 'mcol_site_node_uq');
            $t->index(['status', 'last_heartbeat_at', 'id'], 'mcol_status_hb_idx');
        });

        $schema->create('monitor_checks', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('site_id');
            $t->string('check_key', 64);
            $t->string('display_name', 120);
            $t->boolean('enabled')->default(true);
            $t->boolean('implemented')->default(true);
            $t->unsignedInteger('interval_seconds')->default(300);
            $t->unsignedInteger('grace_seconds')->default(300);
            $t->dateTime('next_due_at', 6)->nullable();
            $t->dateTime('last_completed_at', 6)->nullable();
            $t->string('last_outcome', 20)->nullable();
            $t->json('config_json')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->string('runbook_key', 16)->nullable();
            $t->string('owner_node', 64)->nullable();
            $t->unsignedInteger('consecutive_failures')->default(0);
            $t->dateTime('paused_until', 6)->nullable();
            $t->string('pause_reason', 64)->nullable();
            $t->dateTime('created_at', 6)->nullable();
            $t->dateTime('updated_at', 6)->nullable();
            $t->unique(['site_id', 'check_key'], 'mchk_site_key_uq');
            $t->index(['enabled', 'next_due_at', 'id'], 'mchk_due_idx');
        });

        $schema->create('monitor_check_runs', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('check_id');
            $t->string('execution_key', 191)->unique();
            $t->string('trigger_type', 16); // SCHEDULE / RECHECK / MANUAL
            $t->unsignedInteger('attempt_count')->default(0);
            $t->dateTime('scheduled_at', 6)->nullable();
            $t->dateTime('started_at', 6)->nullable();
            $t->dateTime('finished_at', 6)->nullable();
            $t->string('execution_status', 16)->default('QUEUED');
            $t->string('outcome', 20)->nullable();
            $t->unsignedInteger('query_count')->default(0);
            $t->unsignedInteger('query_ms')->default(0);
            $t->unsignedInteger('max_query_ms')->default(0);
            $t->string('watermark_hash', 64)->nullable();
            $t->boolean('verification_complete')->default(false);
            $t->json('checkpoint_json')->nullable();
            $t->string('failure_code', 64)->nullable();
            $t->string('summary', 500)->nullable();
            $t->dateTime('expires_at', 6)->nullable();
            $t->index(['check_id', 'id'], 'mrun_check_idx');
            $t->index(['execution_status', 'started_at', 'id'], 'mrun_status_idx');
            $t->index(['expires_at', 'id'], 'mrun_exp_idx');
        });

        $schema->create('monitor_check_attempts', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('run_id');
            $t->unsignedInteger('attempt_no');
            $t->dateTime('started_at', 6)->nullable();
            $t->dateTime('finished_at', 6)->nullable();
            $t->string('execution_status', 16);
            $t->string('failure_code', 64)->nullable();
            $t->unsignedInteger('query_count')->default(0);
            $t->unsignedInteger('query_ms')->default(0);
            $t->json('checkpoint_json')->nullable();
            $t->dateTime('expires_at', 6)->nullable();
            $t->unique(['run_id', 'attempt_no'], 'matt_run_no_uq');
            $t->index(['expires_at', 'id'], 'matt_exp_idx');
        });

        $schema->create('monitor_logs', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('collector_id')->nullable();
            $t->uuid('event_id');
            $t->string('payload_hash', 64);
            $t->unsignedBigInteger('site_id');
            $t->string('node_code', 64)->nullable();
            $t->unsignedBigInteger('check_id')->nullable();
            $t->unsignedBigInteger('run_id')->nullable();
            $t->string('source_kind', 20);
            $t->string('severity', 10);
            $t->string('outcome', 20)->nullable();
            $t->string('check_key', 64)->nullable();
            $t->string('error_code', 64)->nullable();
            $t->date('business_date')->nullable();
            $t->dateTime('occurred_at', 6);
            $t->dateTime('received_at', 6);
            $t->string('summary', 500);
            $t->json('metrics_json')->nullable();
            $t->json('evidence_json')->nullable();
            $t->unsignedBigInteger('incident_id')->nullable();
            $t->unsignedInteger('occurrence_delta')->default(1);
            $t->string('retention_class', 10);
            $t->dateTime('expires_at', 6)->nullable();
            $t->unique(['collector_id', 'event_id'], 'mlog_col_event_uq');
            $t->index(['site_id', 'id'], 'mlog_site_idx');
            $t->index(['site_id', 'severity', 'id'], 'mlog_site_sev_idx');
            $t->index(['severity', 'id'], 'mlog_sev_idx');
            $t->index(['retention_class', 'expires_at', 'id'], 'mlog_ret_idx');
            $t->index(['incident_id', 'id'], 'mlog_inc_idx');
            $t->index(['received_at', 'id'], 'mlog_recv_idx');
            $t->index(['check_key', 'id'], 'mlog_ckey_idx');
        });

        $schema->create('monitor_incidents', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('public_id', 16)->unique();
            $t->unsignedBigInteger('site_id');
            $t->unsignedBigInteger('check_id')->nullable();
            $t->string('check_key', 64)->nullable();
            $t->char('fingerprint', 64)->unique();
            $t->string('error_code', 64)->nullable();
            $t->string('severity', 10);
            $t->string('title', 200);
            $t->json('scope_json')->nullable();
            $t->string('node_code', 64)->nullable();
            $t->date('business_date')->nullable();
            $t->string('observation_state', 12)->default('ACTIVE');
            $t->string('workflow_status', 16)->default('OPEN');
            $t->unsignedInteger('generation')->default(1);
            $t->dateTime('first_seen_at', 6);
            $t->dateTime('last_seen_at', 6);
            $t->dateTime('last_verified_at', 6)->nullable();
            $t->dateTime('recovered_at', 6)->nullable();
            $t->dateTime('resolved_at', 6)->nullable();
            $t->dateTime('closed_at', 6)->nullable();
            $t->unsignedBigInteger('occurrence_count')->default(1);
            $t->unsignedInteger('healthy_streak')->default(0);
            $t->unsignedBigInteger('last_source_sequence')->nullable();
            $t->unsignedBigInteger('assigned_user_id')->nullable();
            $t->string('assigned_name', 100)->nullable();
            $t->dateTime('acknowledged_at', 6)->nullable();
            $t->unsignedBigInteger('acknowledged_user_id')->nullable();
            $t->string('acknowledged_name', 100)->nullable();
            $t->dateTime('next_reminder_at', 6)->nullable();
            $t->unsignedInteger('reminder_slot')->default(0);
            $t->dateTime('snoozed_until', 6)->nullable();
            $t->string('suppression_reason', 255)->nullable();
            $t->string('runbook_key', 16)->nullable();
            $t->unsignedInteger('runbook_version')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->json('evidence_snapshot_json')->nullable();
            $t->json('resolution_json')->nullable();
            $t->string('last_delivery_status', 16)->nullable();
            $t->dateTime('last_delivered_at', 6)->nullable();
            $t->dateTime('expires_at', 6)->nullable();
            $t->dateTime('created_at', 6)->nullable();
            $t->dateTime('updated_at', 6)->nullable();
            $t->index(['site_id', 'workflow_status', 'id'], 'minc_site_wf_idx');
            $t->index(['workflow_status', 'id'], 'minc_wf_idx');
            $t->index(['site_id', 'severity', 'id'], 'minc_site_sev_idx');
            $t->index(['next_reminder_at', 'id'], 'minc_rem_idx');
            $t->index(['expires_at', 'id'], 'minc_exp_idx');
            $t->index(['check_id', 'observation_state', 'id'], 'minc_chk_obs_idx');
        });

        $schema->create('monitor_incident_events', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('incident_id');
            $t->unsignedInteger('generation');
            $t->string('event_type', 32);
            $t->unsignedBigInteger('actor_user_id')->nullable();
            $t->string('actor_name', 100)->nullable();
            $t->dateTime('created_at', 6);
            $t->string('reason', 1000)->nullable();
            $t->json('before_json')->nullable();
            $t->json('after_json')->nullable();
            $t->string('idempotency_key', 191)->unique();
            $t->dateTime('expires_at', 6)->nullable();
            $t->index(['incident_id', 'id'], 'mevt_inc_idx');
            $t->index(['expires_at', 'id'], 'mevt_exp_idx');
        });

        $schema->create('monitor_notifications', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('incident_id')->nullable();
            $t->unsignedInteger('generation')->default(1);
            $t->string('notification_key', 191)->unique();
            $t->string('kind', 24);
            $t->string('route_key', 64);
            $t->string('severity', 10)->default('ERROR');
            $t->json('payload_snapshot_json')->nullable();
            $t->string('status', 16)->default('PENDING');
            $t->unsignedInteger('attempt_count')->default(0);
            $t->dateTime('available_at', 6);
            $t->dateTime('created_at', 6);
            $t->unsignedBigInteger('delivery_batch_id')->nullable();
            $t->string('lease_token', 64)->nullable();
            $t->dateTime('lease_expires_at', 6)->nullable();
            $t->dateTime('delivered_at', 6)->nullable();
            $t->unsignedSmallInteger('last_http_status')->nullable();
            $t->string('last_error_code', 64)->nullable();
            $t->string('cancel_reason', 255)->nullable();
            $t->dateTime('expires_at', 6)->nullable();
            $t->index(['status', 'available_at', 'id'], 'mntf_status_avail_idx');
            $t->index(['status', 'lease_expires_at', 'id'], 'mntf_lease_idx');
            $t->index(['incident_id', 'id'], 'mntf_inc_idx');
            $t->index(['expires_at', 'id'], 'mntf_exp_idx');
        });

        $schema->create('monitor_delivery_batches', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('public_id', 24)->unique();
            $t->string('route_key', 64);
            $t->json('notification_ids_json');
            $t->json('payload_snapshot_json')->nullable();
            $t->dateTime('created_at', 6);
            $t->dateTime('delivered_at', 6)->nullable();
            $t->string('status', 16)->default('PENDING');
            $t->dateTime('expires_at', 6)->nullable();
            $t->index(['status', 'id'], 'mbat_status_idx');
            $t->index(['expires_at', 'id'], 'mbat_exp_idx');
        });

        $schema->create('monitor_notification_attempts', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('notification_id');
            $t->unsignedBigInteger('delivery_batch_id')->nullable();
            $t->unsignedInteger('attempt_no');
            $t->dateTime('started_at', 6);
            $t->dateTime('finished_at', 6)->nullable();
            $t->string('result', 16); // DELIVERED / RETRY / BLOCKED / CANCELLED
            $t->unsignedSmallInteger('http_status')->nullable();
            $t->unsignedInteger('retry_after_seconds')->nullable();
            $t->string('sanitized_error', 255)->nullable();
            $t->dateTime('expires_at', 6)->nullable();
            $t->unique(['notification_id', 'attempt_no'], 'mnat_ntf_no_uq');
            $t->index(['expires_at', 'id'], 'mnat_exp_idx');
        });

        $schema->create('monitor_runbooks', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('runbook_key', 16);
            $t->unsignedInteger('version');
            $t->string('title', 150);
            $t->text('symptoms')->nullable();
            $t->text('impact')->nullable();
            $t->text('first_checks')->nullable();
            $t->text('read_only_checks')->nullable();
            $t->text('decision_steps')->nullable();
            $t->text('recovery_procedure')->nullable();
            $t->text('verification_steps')->nullable();
            $t->text('escalation')->nullable();
            $t->text('prevention_notes')->nullable();
            $t->string('owner_group', 64)->nullable();
            $t->dateTime('reviewed_at', 6)->nullable();
            $t->boolean('enabled')->default(true);
            $t->dateTime('created_at', 6)->nullable();
            $t->dateTime('updated_at', 6)->nullable();
            $t->unique(['runbook_key', 'version'], 'mrb_key_ver_uq');
            $t->index(['enabled', 'runbook_key', 'version'], 'mrb_enabled_idx');
        });

        $schema->create('monitor_audit_events', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('actor_user_id')->nullable();
            $t->string('actor_name', 100)->nullable();
            $t->string('action', 64);
            $t->string('target_type', 32);
            $t->string('target_id', 64);
            $t->json('before_json')->nullable();
            $t->json('after_json')->nullable();
            $t->string('reason', 1000)->nullable();
            $t->dateTime('created_at', 6);
            $t->dateTime('expires_at', 6)->nullable();
            $t->index(['target_type', 'target_id', 'id'], 'maud_target_idx');
            $t->index(['actor_user_id', 'id'], 'maud_actor_idx');
            $t->index(['expires_at', 'id'], 'maud_exp_idx');
        });

        $schema->create('monitor_counters', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('site_id');
            $t->string('counter_key', 64);
            $t->dateTime('bucket_start', 6);
            $t->json('counts_json');
            $t->dateTime('calculated_at', 6);
            $t->unique(['site_id', 'counter_key', 'bucket_start'], 'mcnt_uq');
            $t->index(['counter_key', 'bucket_start'], 'mcnt_key_bucket_idx');
        });

        $schema->create('monitor_recheck_requests', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('request_id', 36)->unique();
            $t->unsignedBigInteger('check_id');
            $t->unsignedBigInteger('incident_id')->nullable();
            $t->json('scope_json')->nullable();
            $t->string('scope_hash', 64);
            $t->string('status', 16)->default('QUEUED'); // QUEUED / RUNNING / DONE / BUDGET_WAIT / FAILED
            $t->unsignedBigInteger('requested_user_id')->nullable();
            $t->string('requested_name', 100)->nullable();
            $t->dateTime('requested_at', 6);
            $t->dateTime('started_at', 6)->nullable();
            $t->dateTime('finished_at', 6)->nullable();
            $t->unsignedBigInteger('run_id')->nullable();
            $t->string('result_outcome', 20)->nullable();
            $t->string('message', 500)->nullable();
            $t->dateTime('expires_at', 6)->nullable();
            $t->index(['status', 'id'], 'mrck_status_idx');
            $t->index(['check_id', 'scope_hash', 'status'], 'mrck_chk_scope_idx');
            $t->index(['expires_at', 'id'], 'mrck_exp_idx');
        });

        $schema->create('monitor_settings', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('setting_key', 64)->unique();
            $t->json('value_json')->nullable();
            $t->unsignedBigInteger('updated_user_id')->nullable();
            $t->string('updated_name', 100)->nullable();
            $t->string('reason', 1000)->nullable();
            $t->dateTime('expires_at', 6)->nullable();
            $t->dateTime('updated_at', 6)->nullable();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        foreach ([
            'monitor_settings', 'monitor_recheck_requests', 'monitor_counters', 'monitor_audit_events', 'monitor_runbooks',
            'monitor_notification_attempts', 'monitor_delivery_batches', 'monitor_notifications', 'monitor_incident_events',
            'monitor_incidents', 'monitor_logs', 'monitor_check_attempts', 'monitor_check_runs', 'monitor_checks',
            'monitor_collectors', 'monitor_sites',
        ] as $table) {
            $schema->dropIfExists($table);
        }
    }
};
