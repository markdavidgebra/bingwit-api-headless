<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boat_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fishing_boat_id')
                  ->constrained('fishing_boats')
                  ->onDelete('cascade');
            $table->foreignId('user_id')
                  ->constrained('users')
                  ->onDelete('cascade');

            $table->timestamp('trip_at');
            $table->unsignedSmallInteger('passengers_count')->default(1);
            $table->text('notes')->nullable();
            $table->decimal('total_price', 10, 2)->default(0);

            $table->enum('status', ['pending', 'confirmed', 'cancelled', 'completed'])
                  ->default('pending');

            $table->timestamps();

            $table->index(['fishing_boat_id', 'trip_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boat_bookings');
    }
};
