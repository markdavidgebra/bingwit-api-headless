<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catch_id')
                  ->constrained('catches')
                  ->onDelete('cascade');
            $table->foreignId('product_id')
                  ->constrained()
                  ->onDelete('cascade');
            $table->timestamps();

            $table->unique(['catch_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_tags');
    }
};