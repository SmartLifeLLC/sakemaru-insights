<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        Schema::connection('sakemaru')->create('ins_filament_filter_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->integer('tenant_id')->nullable();
            $table->string('name');
            $table->string('resource');
            $table->json('filters');
            $table->json('indicators');
            $table->string('icon')->nullable();
            $table->string('color')->nullable();
            $table->boolean('is_public');
            $table->boolean('is_global_favorite');
            $table->string('status')->default('approved');
            $table->smallInteger('sort_order')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('sakemaru')->dropIfExists('ins_filament_filter_sets');
    }
};
