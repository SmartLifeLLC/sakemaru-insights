<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        Schema::connection('sakemaru')->create('ins_filament_filter_set_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->foreignId('filter_set_id');
            $table->smallInteger('sort_order')->default(1);
            $table->boolean('is_visible')->default(true);
            $table->integer('tenant_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('sakemaru')->dropIfExists('ins_filament_filter_set_user');
    }
};
