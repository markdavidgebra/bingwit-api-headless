<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fishing_boats', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_id')->nullable()->index();

            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->string('departure_point')->nullable();

            $table->unsignedInteger('capacity')->default(6);
            $table->decimal('price_per_person', 10, 2)->default(0);
            $table->unsignedSmallInteger('duration_hours')->default(4);

            $table->string('captain_name')->nullable();
            $table->string('contact_phone')->nullable();

            $table->enum('status', ['available', 'unavailable', 'maintenance'])
                  ->default('available');

            $table->string('cover_image')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fishing_boats');
    }
};
