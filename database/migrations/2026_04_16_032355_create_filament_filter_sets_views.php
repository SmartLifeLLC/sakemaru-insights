<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Advanced Tables v4.1.x の HasUserViews.php が filament_filter_set_user 等を
 * prefix なしでハードコードしているため、prefix 付きテーブルへの VIEW を作成。
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = DB::getTablePrefix();

        if (empty($prefix)) {
            return;
        }

        DB::connection('sakemaru')->statement(
            "CREATE OR REPLACE VIEW filament_filter_set_user AS SELECT * FROM {$prefix}filament_filter_set_user"
        );
        DB::connection('sakemaru')->statement(
            "CREATE OR REPLACE VIEW filament_filter_sets AS SELECT * FROM {$prefix}filament_filter_sets"
        );
        DB::connection('sakemaru')->statement(
            "CREATE OR REPLACE VIEW filament_filter_sets_managed_default_views AS SELECT * FROM {$prefix}filament_filter_sets_managed_default_views"
        );
    }

    public function down(): void
    {
        DB::connection('sakemaru')->statement('DROP VIEW IF EXISTS filament_filter_set_user');
        DB::connection('sakemaru')->statement('DROP VIEW IF EXISTS filament_filter_sets');
        DB::connection('sakemaru')->statement('DROP VIEW IF EXISTS filament_filter_sets_managed_default_views');
    }
};
