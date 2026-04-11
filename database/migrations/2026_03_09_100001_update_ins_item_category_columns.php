<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ret_daily_sales / ret_hourly_sales の型変更に合わせて ins_ テーブルを更新:
 * - item_code: varchar(20) → unsignedInteger
 * - category_code: varchar(20) → unsignedSmallInteger
 * - item_id: 新規追加 (bigint unsigned nullable)
 * - item_category_id: 新規追加 (bigint unsigned nullable)
 *
 * UNIQUE KEY に item_code / category_code が含まれるため、
 * 一旦 UNIQUE を DROP → カラム変更 → UNIQUE 再作成 の手順で実施。
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─── 1. Fact テーブル ───

        // ins_sales_fact: UNIQUE(business_date, ret_store_id, item_code)
        Schema::table('sales_fact', function (Blueprint $table) {
            $table->dropUnique('sales_fact_unique');
            $table->dropIndex('ins_sales_fact_business_date_item_code_index');
        });
        Schema::table('sales_fact', function (Blueprint $table) {
            $table->unsignedInteger('item_code')->change();
            $table->unsignedSmallInteger('category_code')->nullable()->change();
            $table->unsignedBigInteger('item_id')->nullable()->after('item_code');
            $table->unsignedBigInteger('item_category_id')->nullable()->after('category_code');

            $table->unique(['business_date', 'ret_store_id', 'item_code'], 'sales_fact_unique');
            $table->index(['business_date', 'item_code'], 'ins_sales_fact_business_date_item_code_index');
            $table->index('item_id');
            $table->index('item_category_id');
        });

        // ins_hourly_sales_fact: UNIQUE(business_date, ret_store_id, item_code, time_slot)
        Schema::table('hourly_sales_fact', function (Blueprint $table) {
            $table->dropUnique('hourly_fact_unique');
        });
        Schema::table('hourly_sales_fact', function (Blueprint $table) {
            $table->unsignedInteger('item_code')->change();
            $table->unsignedSmallInteger('category_code')->nullable()->change();
            $table->unsignedBigInteger('item_id')->nullable()->after('item_code');
            $table->unsignedBigInteger('item_category_id')->nullable()->after('category_code');

            $table->unique(['business_date', 'ret_store_id', 'item_code', 'time_slot'], 'hourly_fact_unique');
            $table->index('item_id');
            $table->index('item_category_id');
        });

        // ─── 2. Daily Summary テーブル ───

        // ins_daily_item_sales: UNIQUE(business_date, item_code)
        Schema::table('daily_item_sales', function (Blueprint $table) {
            $table->dropUnique('dis_unique');
            $table->dropIndex('ins_daily_item_sales_item_code_business_date_index');
            $table->dropIndex('ins_daily_item_sales_category_code_business_date_index');
        });
        Schema::table('daily_item_sales', function (Blueprint $table) {
            $table->unsignedInteger('item_code')->change();
            $table->unsignedSmallInteger('category_code')->nullable()->change();
            $table->unsignedBigInteger('item_id')->nullable()->after('item_code');
            $table->unsignedBigInteger('item_category_id')->nullable()->after('category_code');

            $table->unique(['business_date', 'item_code'], 'dis_unique');
            $table->index(['item_code', 'business_date']);
            $table->index(['category_code', 'business_date']);
            $table->index('item_id');
            $table->index('item_category_id');
        });

        // ins_daily_category_sales: UNIQUE(business_date, category_code)
        Schema::table('daily_category_sales', function (Blueprint $table) {
            $table->dropUnique('dcs_unique');
            $table->dropIndex('ins_daily_category_sales_category_code_business_date_index');
        });
        Schema::table('daily_category_sales', function (Blueprint $table) {
            $table->unsignedSmallInteger('category_code')->change();
            $table->unsignedBigInteger('item_category_id')->nullable()->after('category_code');

            $table->unique(['business_date', 'category_code'], 'dcs_unique');
            $table->index(['category_code', 'business_date']);
            $table->index('item_category_id');
        });

        // ins_daily_store_item_sales: UNIQUE(business_date, ret_store_id, item_code)
        Schema::table('daily_store_item_sales', function (Blueprint $table) {
            $table->dropUnique('dsis_unique');
            $table->dropIndex('ins_daily_store_item_sales_item_code_business_date_index');
        });
        Schema::table('daily_store_item_sales', function (Blueprint $table) {
            $table->unsignedInteger('item_code')->change();
            $table->unsignedSmallInteger('category_code')->nullable()->change();
            $table->unsignedBigInteger('item_id')->nullable()->after('item_code');
            $table->unsignedBigInteger('item_category_id')->nullable()->after('category_code');

            $table->unique(['business_date', 'ret_store_id', 'item_code'], 'dsis_unique');
            $table->index(['item_code', 'business_date']);
            $table->index('item_id');
            $table->index('item_category_id');
        });

        // ─── 3. Monthly Summary テーブル ───

        // ins_monthly_item_sales: UNIQUE(year_month, item_code)
        Schema::table('monthly_item_sales', function (Blueprint $table) {
            $table->dropUnique('mis_unique');
            $table->dropIndex('ins_monthly_item_sales_item_code_year_month_index');
        });
        Schema::table('monthly_item_sales', function (Blueprint $table) {
            $table->unsignedInteger('item_code')->change();
            $table->unsignedSmallInteger('category_code')->nullable()->change();
            $table->unsignedBigInteger('item_id')->nullable()->after('item_code');
            $table->unsignedBigInteger('item_category_id')->nullable()->after('category_code');

            $table->unique(['year_month', 'item_code'], 'mis_unique');
            $table->index(['item_code', 'year_month']);
            $table->index('item_id');
            $table->index('item_category_id');
        });

        // ─── 4. Dimension テーブル ───

        // ins_dim_item: UNIQUE(item_code)
        Schema::table('dim_item', function (Blueprint $table) {
            $table->dropUnique('ins_dim_item_item_code_unique');
            $table->dropIndex('ins_dim_item_category_code_index');
        });
        Schema::table('dim_item', function (Blueprint $table) {
            $table->unsignedInteger('item_code')->change();
            $table->unsignedSmallInteger('category_code')->nullable()->change();

            $table->unique('item_code');
            $table->index('category_code');
        });

        // ins_dim_category: UNIQUE(category_code)
        Schema::table('dim_category', function (Blueprint $table) {
            $table->dropUnique('ins_dim_category_category_code_unique');
        });
        Schema::table('dim_category', function (Blueprint $table) {
            $table->unsignedSmallInteger('category_code')->change();
            $table->unsignedBigInteger('item_category_id')->nullable()->after('category_code');

            $table->unique('category_code');
            $table->index('item_category_id');
        });
    }

    public function down(): void
    {
        // Fact テーブル
        foreach (['sales_fact', 'hourly_sales_fact', 'daily_item_sales', 'daily_store_item_sales', 'monthly_item_sales'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('item_code', 20)->change();
                $table->string('category_code', 20)->nullable()->change();
                $table->dropColumn(['item_id', 'item_category_id']);
            });
        }

        Schema::table('daily_category_sales', function (Blueprint $table) {
            $table->string('category_code', 20)->change();
            $table->dropColumn('item_category_id');
        });

        Schema::table('dim_item', function (Blueprint $table) {
            $table->string('item_code', 20)->change();
            $table->string('category_code', 20)->nullable()->change();
        });

        Schema::table('dim_category', function (Blueprint $table) {
            $table->string('category_code', 20)->change();
            $table->dropColumn('item_category_id');
        });
    }
};
