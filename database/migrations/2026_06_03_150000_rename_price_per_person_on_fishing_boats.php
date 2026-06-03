<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fishing_boats', function (Blueprint $table) {
            $table->renameColumn('price_per_person', 'trip_price');
        });
    }

    public function down(): void
    {
        Schema::table('fishing_boats', function (Blueprint $table) {
            $table->renameColumn('trip_price', 'price_per_person');
        });
    }
};
