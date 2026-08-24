<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('economy_settings')->where('key', 'fish_points_marketplace_purchase')->exists()) {
            DB::table('economy_settings')->insert([
                'key' => 'fish_points_marketplace_purchase',
                'value' => '10',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('economy_settings')->where('key', 'fish_points_marketplace_purchase')->delete();
    }
};
