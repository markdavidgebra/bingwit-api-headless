<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('star_cost')->nullable()->after('original_price');
            $table->boolean('is_points_only')->default(false)->after('star_cost');
        });

        Schema::create('product_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('stars_spent');
            $table->string('status', 20)->default('pending');
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_claims');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['star_cost', 'is_points_only']);
        });
    }
};
