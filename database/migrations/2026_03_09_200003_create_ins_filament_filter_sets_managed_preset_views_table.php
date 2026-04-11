<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        Schema::connection('sakemaru')->create('ins_filament_filter_sets_managed_preset_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->string('name');
            $table->string('label')->nullable();
            $table->string('resource');
            $table->boolean('is_favorite')->default(true);
            $table->smallInteger('sort_order')->default(1);
            $table->integer('tenant_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('sakemaru')->dropIfExists('ins_filament_filter_sets_managed_preset_views');
    }
};
