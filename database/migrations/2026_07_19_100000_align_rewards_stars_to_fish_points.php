<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catches', function (Blueprint $table) {
            if (! Schema::hasColumn('catches', 'stars_received')) {
                $table->unsignedInteger('stars_received')->default(0)->after('fish_points_received');
            }
        });

        Schema::table('catch_star_gifts', function (Blueprint $table) {
            if (! Schema::hasColumn('catch_star_gifts', 'stars')) {
                $table->unsignedInteger('stars')->nullable()->after('fish_points');
            }
        });

        Schema::table('reward_items', function (Blueprint $table) {
            if (! Schema::hasColumn('reward_items', 'fish_points_cost')) {
                $table->unsignedInteger('fish_points_cost')->nullable()->after('star_cost');
            }
        });

        Schema::table('redemptions', function (Blueprint $table) {
            if (! Schema::hasColumn('redemptions', 'fish_points_spent')) {
                $table->unsignedInteger('fish_points_spent')->nullable()->after('stars_spent');
            }
        });

        // Tackle shop redeems with Fish Points. Seed cost from existing star_cost × rate.
        $rate = (int) (DB::table('economy_settings')->where('key', 'fish_points_per_star')->value('value') ?? 10);
        if ($rate < 1) {
            $rate = 10;
        }

        DB::table('reward_items')
            ->whereNull('fish_points_cost')
            ->update([
                'fish_points_cost' => DB::raw('GREATEST(1, star_cost * ' . (int) $rate . ')'),
            ]);

        $settings = [
            'stars_boat_booking' => '5',
            'stars_afilink_bonus' => '25',
        ];

        foreach ($settings as $key => $value) {
            $exists = DB::table('economy_settings')->where('key', $key)->exists();
            if (! $exists) {
                DB::table('economy_settings')->insert([
                    'key' => $key,
                    'value' => $value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('redemptions', function (Blueprint $table) {
            if (Schema::hasColumn('redemptions', 'fish_points_spent')) {
                $table->dropColumn('fish_points_spent');
            }
        });

        Schema::table('reward_items', function (Blueprint $table) {
            if (Schema::hasColumn('reward_items', 'fish_points_cost')) {
                $table->dropColumn('fish_points_cost');
            }
        });

        Schema::table('catch_star_gifts', function (Blueprint $table) {
            if (Schema::hasColumn('catch_star_gifts', 'stars')) {
                $table->dropColumn('stars');
            }
        });

        Schema::table('catches', function (Blueprint $table) {
            if (Schema::hasColumn('catches', 'stars_received')) {
                $table->dropColumn('stars_received');
            }
        });

        DB::table('economy_settings')
            ->whereIn('key', ['stars_boat_booking', 'stars_afilink_bonus'])
            ->delete();
    }
};
