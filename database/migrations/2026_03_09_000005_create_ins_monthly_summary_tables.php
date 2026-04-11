<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // monthly_store_sales (ins_monthly_store_sales) — 粒度: 月 × 店舗
        Schema::create('monthly_store_sales', function (Blueprint $table) {
            $table->id();
            $table->char('year_month', 7)->comment('YYYY-MM format');
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

            $table->unique(['year_month', 'ret_store_id'], 'mss_unique');
            $table->index(['ret_store_id', 'year_month']);
            $table->index('year_month');
        });

        // monthly_item_sales (ins_monthly_item_sales) — 粒度: 月 × 商品
        Schema::create('monthly_item_sales', function (Blueprint $table) {
            $table->id();
            $table->char('year_month', 7)->comment('YYYY-MM format');
            $table->string('item_code', 20);
            $table->string('item_name', 255)->nullable();
            $table->string('category_code', 20)->nullable();
            $table->string('category_name', 255)->nullable();
            $table->integer('sales_amount')->default(0);
            $table->integer('sales_qty')->default(0);
            $table->integer('return_amount')->default(0);
            $table->integer('gross_profit')->default(0);
            $table->timestamps();

            $table->unique(['year_month', 'item_code'], 'mis_unique');
            $table->index(['item_code', 'year_month']);
            $table->index('year_month');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_item_sales');
        Schema::dropIfExists('monthly_store_sales');
    }
};
