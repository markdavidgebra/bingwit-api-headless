<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fishing_boats', function (Blueprint $table) {
            $table->unsignedTinyInteger('cover_focus_x')->default(50)->after('cover_image');
            $table->unsignedTinyInteger('cover_focus_y')->default(50)->after('cover_focus_x');
        });
    }

    public function down(): void
    {
        Schema::table('fishing_boats', function (Blueprint $table) {
            $table->dropColumn(['cover_focus_x', 'cover_focus_y']);
        });
    }
};
