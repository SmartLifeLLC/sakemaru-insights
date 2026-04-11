<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // dim_store (ins_dim_store)
        Schema::create('dim_store', function (Blueprint $table) {
            $table->id();
            $table->char('store_code', 10)->unique();
            $table->string('store_name', 100)->nullable();
            $table->string('area', 50)->nullable();
            $table->string('region', 50)->nullable();
            $table->timestamps();
        });

        // dim_item (ins_dim_item)
        Schema::create('dim_item', function (Blueprint $table) {
            $table->id();
            $table->string('item_code', 20)->unique();
            $table->string('item_name', 255)->nullable();
            $table->string('category_code', 20)->nullable()->index();
            $table->string('brand', 100)->nullable();
            $table->timestamps();
        });

        // dim_category (ins_dim_category)
        Schema::create('dim_category', function (Blueprint $table) {
            $table->id();
            $table->string('category_code', 20)->unique();
            $table->string('category_name', 255)->nullable();
            $table->timestamps();
        });

        // dim_date (ins_dim_date)
        Schema::create('dim_date', function (Blueprint $table) {
            $table->date('date')->primary();
            $table->smallInteger('year');
            $table->tinyInteger('month');
            $table->tinyInteger('day');
            $table->tinyInteger('weekday')->comment('0=Sun, 6=Sat');
            $table->tinyInteger('week_of_year');
        });

        // dim_time_slot (ins_dim_time_slot)
        Schema::create('dim_time_slot', function (Blueprint $table) {
            $table->id();
            $table->string('time_slot', 10)->unique();
            $table->string('label', 50)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dim_time_slot');
        Schema::dropIfExists('dim_date');
        Schema::dropIfExists('dim_category');
        Schema::dropIfExists('dim_item');
        Schema::dropIfExists('dim_store');
    }
};
