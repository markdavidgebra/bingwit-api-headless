<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catch_star_gifts', function (Blueprint $table) {
            $table->string('message', 500)->nullable()->after('stars');
        });
    }

    public function down(): void
    {
        Schema::table('catch_star_gifts', function (Blueprint $table) {
            $table->dropColumn('message');
        });
    }
};
