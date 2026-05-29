<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                  ->constrained()
                  ->onDelete('cascade');
            $table->foreignId('catch_id')
                  ->constrained('catches')
                  ->onDelete('cascade');
            $table->timestamps();

            // One like per user per catch only
            $table->unique(['user_id', 'catch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('likes');
    }
};