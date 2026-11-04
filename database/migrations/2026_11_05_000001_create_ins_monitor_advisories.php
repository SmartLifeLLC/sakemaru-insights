<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 脆弱性情報（外部情報源から収集した公開アドバイザリ）。monitor_store 接続（prefix ins_）。
 * 業務テーブルへの FK は作らない。秘密値は保存しない。
 */
return new class extends Migration
{
    protected $connection = 'monitor_store';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        $schema->create('monitor_advisories', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('source', 16);                       // nvd / github / ubuntu / kev / aws / jvn
            $t->string('external_id', 64);                  // CVE-… / GHSA-… / USN-… / JVNDB-… / AWS の ID
            $t->string('cve_id', 32)->nullable();
            $t->string('title', 500);
            $t->text('summary')->nullable();
            $t->string('severity', 16)->default('UNKNOWN'); // CRITICAL / HIGH / MEDIUM / LOW / UNKNOWN
            $t->decimal('cvss', 3, 1)->nullable();
            $t->decimal('epss', 6, 5)->nullable();
            $t->boolean('kev')->default(false);             // CISA KEV に掲載（悪用確認）
            $t->string('url', 500)->nullable();
            $t->json('components_json')->nullable();        // 一致した製品キー（apache / php / laravel …）
            $t->json('affected_json')->nullable();          // 影響パッケージ・版の生情報
            $t->boolean('installed_affected')->default(false); // 導入中の版が影響範囲に入る（判定できた場合のみ）
            $t->dateTime('published_at', 6)->nullable();
            $t->dateTime('source_updated_at', 6)->nullable();
            $t->dateTime('first_seen_at', 6);
            $t->dateTime('last_seen_at', 6);
            $t->string('status', 16)->default('NEW');       // NEW / ACKNOWLEDGED / NOT_APPLICABLE / RESOLVED
            $t->unsignedBigInteger('status_user_id')->nullable();
            $t->string('status_name', 100)->nullable();
            $t->string('status_note', 1000)->nullable();
            $t->dateTime('status_at', 6)->nullable();
            $t->unique(['source', 'external_id'], 'madv_src_ext_uq');
            $t->index(['status', 'severity', 'published_at'], 'madv_status_sev_idx');
            $t->index(['cve_id'], 'madv_cve_idx');
            $t->index(['first_seen_at'], 'madv_first_idx');
        });

        $schema->create('monitor_advisory_fetches', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('source', 16);
            $t->dateTime('started_at', 6);
            $t->dateTime('finished_at', 6)->nullable();
            $t->string('status', 16)->default('RUNNING');  // RUNNING / OK / FAILED
            $t->unsignedInteger('fetched_count')->default(0);
            $t->unsignedInteger('new_count')->default(0);
            $t->string('error', 1000)->nullable();
            $t->index(['source', 'started_at'], 'madvf_src_started_idx');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        $schema->dropIfExists('monitor_advisory_fetches');
        $schema->dropIfExists('monitor_advisories');
    }
};
