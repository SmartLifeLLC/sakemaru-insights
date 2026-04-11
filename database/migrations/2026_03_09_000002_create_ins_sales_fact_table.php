<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // sales_fact (ins_sales_fact) — 粒度: 日 × 店舗 × 商品
        // データソース: ret_daily_sales (slip_date → business_date)
        Schema::create('sales_fact', function (Blueprint $table) {
            $table->id();
            $table->date('business_date');
            $table->unsignedBigInteger('ret_store_id');
            $table->unsignedBigInteger('sales_ret_store_id')->nullable();
            $table->unsignedBigInteger('shipping_ret_store_id')->nullable();
            $table->string('item_code', 20);
            $table->string('category_code', 20)->nullable();
            $table->integer('sales_qty')->default(0);
            $table->integer('sales_amount')->default(0);
            $table->integer('return_qty')->default(0);
            $table->integer('return_amount')->default(0);
            $table->integer('gross_profit')->default(0);
            $table->decimal('cost_amount', 11, 2)->default(0);
            $table->timestamps();

            $table->unique(['business_date', 'ret_store_id', 'item_code'], 'sales_fact_unique');
            $table->index(['business_date', 'ret_store_id']);
            $table->index(['business_date', 'item_code']);
            $table->index(['ret_store_id', 'business_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_fact');
    }
};
