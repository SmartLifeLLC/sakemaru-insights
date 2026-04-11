<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // hourly_sales_fact (ins_hourly_sales_fact) — 粒度: 日 × 店舗 × 商品 × 時間帯
        // データソース: ret_hourly_sales (slip_date → business_date)
        Schema::create('hourly_sales_fact', function (Blueprint $table) {
            $table->id();
            $table->date('business_date');
            $table->unsignedBigInteger('ret_store_id');
            $table->string('time_slot', 10);
            $table->string('item_code', 20);
            $table->string('category_code', 20)->nullable();
            $table->integer('sales_qty')->default(0);
            $table->integer('sales_amount')->default(0);
            $table->integer('return_qty')->default(0);
            $table->integer('return_amount')->default(0);
            $table->integer('gross_profit')->default(0);
            $table->decimal('cost_amount', 11, 2)->default(0);
            $table->timestamps();

            $table->unique(['business_date', 'ret_store_id', 'item_code', 'time_slot'], 'hourly_fact_unique');
            $table->index(['business_date', 'ret_store_id', 'time_slot']);
            $table->index(['ret_store_id', 'business_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hourly_sales_fact');
    }
};
