<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catches', function (Blueprint $table) {
            $table->renameColumn('stars_received', 'fish_points_received');
        });

        Schema::table('catch_star_gifts', function (Blueprint $table) {
            $table->renameColumn('stars', 'fish_points');
        });

        Schema::table('merchant_gift_catalog', function (Blueprint $table) {
            $table->renameColumn('star_cost', 'fish_points_cost');
        });

        Schema::table('merchant_gifts', function (Blueprint $table) {
            $table->renameColumn('stars_spent', 'fish_points_spent');
        });
    }

    public function down(): void
    {
        Schema::table('catches', function (Blueprint $table) {
            $table->renameColumn('fish_points_received', 'stars_received');
        });

        Schema::table('catch_star_gifts', function (Blueprint $table) {
            $table->renameColumn('fish_points', 'stars');
        });

        Schema::table('merchant_gift_catalog', function (Blueprint $table) {
            $table->renameColumn('fish_points_cost', 'star_cost');
        });

        Schema::table('merchant_gifts', function (Blueprint $table) {
            $table->renameColumn('fish_points_spent', 'stars_spent');
        });
    }
};
