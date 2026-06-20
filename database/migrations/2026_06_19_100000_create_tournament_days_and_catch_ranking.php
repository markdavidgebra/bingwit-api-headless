<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournament_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')
                  ->constrained('tournaments')
                  ->cascadeOnDelete();
            $table->date('day_date');
            $table->string('label')->nullable();
            $table->timestamps();

            $table->unique(['tournament_id', 'day_date']);
        });

        Schema::create('tournament_day_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_day_id')
                  ->constrained('tournament_days')
                  ->cascadeOnDelete();
            $table->foreignId('user_id')
                  ->constrained('users')
                  ->cascadeOnDelete();
            $table->foreignId('tournament_participant_id')
                  ->nullable()
                  ->constrained('tournament_participants')
                  ->nullOnDelete();
            $table->timestamps();

            $table->unique(['tournament_day_id', 'user_id']);
        });

        Schema::table('catches', function (Blueprint $table) {
            $table->foreignId('tournament_day_id')
                  ->nullable()
                  ->after('user_id')
                  ->constrained('tournament_days')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('catches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tournament_day_id');
        });

        Schema::dropIfExists('tournament_day_participants');
        Schema::dropIfExists('tournament_days');
    }
};
