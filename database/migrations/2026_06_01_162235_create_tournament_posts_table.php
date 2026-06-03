<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournament_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')
                  ->constrained('tournaments')
                  ->onDelete('cascade');
            // Admin author; nullable so admin deletion doesn't wipe history.
            $table->unsignedBigInteger('admin_id')->nullable()->index();

            $table->string('title')->nullable();
            $table->text('body');

            // When TRUE, the post is also surfaced in the global feed via
            // the /feed/announcements endpoint. Default FALSE — tournament
            // posts stay scoped to the tournament feed.
            $table->boolean('cross_post_to_feed')->default(false)->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_posts');
    }
};
