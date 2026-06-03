<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournaments', function (Blueprint $table) {
            $table->id();
            // Admin who created/owns the tournament. Nullable on cascade so
            // deleting an admin doesn't wipe the tournament history.
            $table->unsignedBigInteger('admin_id')->nullable()->index();

            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('location')->nullable();

            $table->decimal('prize_pool', 12, 2)->default(0);
            $table->decimal('entry_fee', 10, 2)->default(0);
            $table->unsignedInteger('max_participants')->nullable();

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('registration_deadline')->nullable();

            $table->enum('status', ['upcoming', 'open', 'active', 'completed', 'cancelled'])
                  ->default('upcoming');

            // Banner / cover image; managed via Spatie when present, but we
            // also keep a column so a quick paste-URL can work.
            $table->string('cover_image')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournaments');
    }
};
