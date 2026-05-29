<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                  ->constrained()
                  ->onDelete('cascade');
            $table->string('fish_species');
            $table->decimal('weight_kg', 6, 2)->nullable();
            $table->decimal('length_cm', 6, 2)->nullable();
            $table->string('bait_used')->nullable();
            $table->string('fishing_method')->nullable();
            $table->text('caption')->nullable();
            $table->string('location')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('media_type')->default('photo');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catches');
    }
};