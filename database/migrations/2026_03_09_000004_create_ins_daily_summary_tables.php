<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // daily_store_sales (ins_daily_store_sales) — 粒度: 日 × 店舗
        Schema::create('daily_store_sales', function (Blueprint $table) {
            $table->id();
            $table->date('business_date');
            $table->unsignedBigInteger('ret_store_id');
            $table->string('store_name', 100)->nullable();
            $table->string('area', 50)->nullable();
            $table->integer('sales_amount')->default(0);
            $table->integer('sales_qty')->default(0);
            $table->integer('return_amount')->default(0);
            $table->integer('return_qty')->default(0);
            $table->integer('gross_profit')->default(0);
            $table->integer('customer_count')->default(0);
            $table->integer('unit_price')->default(0);
            $table->decimal('gross_profit_rate', 5, 2)->default(0);
            $table->timestamps();

            $table->unique(['business_date', 'ret_store_id'], 'dss_unique');
            $table->index(['ret_store_id', 'business_date']);
            $table->index('business_date');
            $table->index(['area', 'business_date']);
        });

        // daily_item_sales (ins_daily_item_sales) — 粒度: 日 × 商品
        Schema::create('daily_item_sales', function (Blueprint $table) {
            $table->id();
            $table->date('business_date');
            $table->string('item_code', 20);
            $table->string('item_name', 255)->nullable();
            $table->string('category_code', 20)->nullable();
            $table->string('category_name', 255)->nullable();
            $table->integer('sales_amount')->default(0);
            $table->integer('sales_qty')->default(0);
            $table->integer('return_amount')->default(0);
            $table->integer('return_qty')->default(0);
            $table->integer('gross_profit')->default(0);
            $table->timestamps();

            $table->unique(['business_date', 'item_code'], 'dis_unique');
            $table->index(['item_code', 'business_date']);
            $table->index(['category_code', 'business_date']);
            $table->index('business_date');
        });

        // daily_category_sales (ins_daily_category_sales) — 粒度: 日 × カテゴリ
        Schema::create('daily_category_sales', function (Blueprint $table) {
            $table->id();
            $table->date('business_date');
            $table->string('category_code', 20);
            $table->string('category_name', 255)->nullable();
            $table->integer('sales_amount')->default(0);
            $table->integer('sales_qty')->default(0);
            $table->integer('return_amount')->default(0);
            $table->integer('gross_profit')->default(0);
            $table->timestamps();

            $table->unique(['business_date', 'category_code'], 'dcs_unique');
            $table->index(['category_code', 'business_date']);
            $table->index('business_date');
        });

        // daily_store_item_sales (ins_daily_store_item_sales) — 粒度: 日 × 店舗 × 商品
        Schema::create('daily_store_item_sales', function (Blueprint $table) {
            $table->id();
            $table->date('business_date');
            $table->unsignedBigInteger('ret_store_id');
            $table->string('store_name', 100)->nullable();
            $table->string('item_code', 20);
            $table->string('item_name', 255)->nullable();
            $table->string('category_code', 20)->nullable();
            $table->string('category_name', 255)->nullable();
            $table->integer('sales_amount')->default(0);
            $table->integer('sales_qty')->default(0);
            $table->integer('return_amount')->default(0);
            $table->integer('gross_profit')->default(0);
            $table->timestamps();

            $table->unique(['business_date', 'ret_store_id', 'item_code'], 'dsis_unique');
            $table->index(['ret_store_id', 'business_date']);
            $table->index(['item_code', 'business_date']);
        });

        // hourly_store_sales (ins_hourly_store_sales) — 粒度: 日 × 店舗 × 時間帯
        Schema::create('hourly_store_sales', function (Blueprint $table) {
            $table->id();
            $table->date('business_date');
            $table->unsignedBigInteger('ret_store_id');
            $table->string('store_name', 100)->nullable();
            $table->string('time_slot', 10);
            $table->integer('sales_amount')->default(0);
            $table->integer('sales_qty')->default(0);
            $table->integer('gross_profit')->default(0);
            $table->integer('customer_count')->default(0);
            $table->timestamps();

            $table->unique(['business_date', 'ret_store_id', 'time_slot'], 'hss_unique');
            $table->index(['ret_store_id', 'business_date']);
            $table->index(['business_date', 'time_slot']);
        });

        // daily_payment_summary (ins_daily_payment_summary) — 粒度: 日 × 店舗 × 支払種別
        Schema::create('daily_payment_summary', function (Blueprint $table) {
            $table->id();
            $table->date('business_date');
            $table->unsignedBigInteger('ret_store_id');
            $table->string('store_name', 100)->nullable();
            $table->string('payment_type', 30);
            $table->string('payment_label', 100)->nullable();
            $table->integer('amount')->default(0);
            $table->integer('count')->default(0);
            $table->timestamps();

            $table->unique(['business_date', 'ret_store_id', 'payment_type'], 'dps_unique');
            $table->index(['ret_store_id', 'business_date']);
            $table->index(['payment_type', 'business_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_payment_summary');
        Schema::dropIfExists('hourly_store_sales');
        Schema::dropIfExists('daily_store_item_sales');
        Schema::dropIfExists('daily_category_sales');
        Schema::dropIfExists('daily_item_sales');
        Schema::dropIfExists('daily_store_sales');
    }
};
