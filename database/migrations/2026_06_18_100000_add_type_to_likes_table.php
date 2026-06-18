<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('likes', 'type')) {
            Schema::table('likes', function (Blueprint $table) {
                $table->string('type', 16)->default('love')->after('catch_id');
            });

            // Existing reactions came from the heart button in the app.
            DB::table('likes')->update(['type' => 'love']);
        }

        Schema::table('likes', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['catch_id']);
        });

        Schema::table('likes', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'catch_id']);
            $table->unique(['user_id', 'catch_id', 'type']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('catch_id')->references('id')->on('catches')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('likes', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['catch_id']);
        });

        Schema::table('likes', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'catch_id', 'type']);
            $table->unique(['user_id', 'catch_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('catch_id')->references('id')->on('catches')->cascadeOnDelete();
            $table->dropColumn('type');
        });
    }
};
