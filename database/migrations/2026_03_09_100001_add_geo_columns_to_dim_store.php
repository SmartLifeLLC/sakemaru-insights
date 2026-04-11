<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dim_store', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('region');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->string('postal_code', 10)->nullable()->after('longitude');
            $table->boolean('has_pos')->default(false)->after('postal_code');
        });
    }

    public function down(): void
    {
        Schema::table('dim_store', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'postal_code', 'has_pos']);
        });
    }
};
