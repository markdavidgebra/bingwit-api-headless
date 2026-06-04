<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('fish_points')->default(0)->after('fishing_style');
            $table->unsignedInteger('stars')->default(0)->after('fish_points');
        });

        Schema::table('catches', function (Blueprint $table) {
            $table->text('fishing_lesson')->nullable()->after('caption');
            $table->unsignedInteger('stars_received')->default(0)->after('fishing_lesson');
            $table->unsignedInteger('lesson_confirmations_count')->default(0)->after('stars_received');
        });

        Schema::create('economy_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('value');
            $table->timestamps();
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->integer('fish_points_delta')->default(0);
            $table->integer('stars_delta')->default(0);
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('catch_lesson_confirmations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catch_id')->constrained('catches')->cascadeOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'catch_id']);
        });

        Schema::create('catch_star_gifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('giver_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('catch_id')->constrained('catches')->cascadeOnDelete();
            $table->unsignedTinyInteger('stars')->default(1);
            $table->timestamps();
        });

        Schema::create('gear_donations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('donor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('catch_id')->nullable()->constrained('catches')->nullOnDelete();
            $table->string('item_name');
            $table->text('description')->nullable();
            $table->string('condition', 40)->default('good');
            $table->string('status', 20)->default('offered');
            $table->timestamps();
        });

        Schema::create('merchant_gift_catalog', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('emoji', 10)->default('🎁');
            $table->unsignedInteger('star_cost')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('merchant_gifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->foreignId('catalog_item_id')->constrained('merchant_gift_catalog')->cascadeOnDelete();
            $table->unsignedInteger('stars_spent');
            $table->string('message', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('reward_items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('image_url')->nullable();
            $table->unsignedInteger('star_cost');
            $table->unsignedInteger('stock')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reward_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('stars_spent');
            $table->string('status', 20)->default('pending');
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamps();
        });

        DB::table('economy_settings')->insert([
            ['key' => 'fish_points_per_star', 'value' => '10', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'fish_points_post_catch', 'value' => '10', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'fish_points_post_lesson', 'value' => '15', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'fish_points_lesson_confirmed', 'value' => '5', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('redemptions');
        Schema::dropIfExists('reward_items');
        Schema::dropIfExists('merchant_gifts');
        Schema::dropIfExists('merchant_gift_catalog');
        Schema::dropIfExists('gear_donations');
        Schema::dropIfExists('catch_star_gifts');
        Schema::dropIfExists('catch_lesson_confirmations');
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('economy_settings');

        Schema::table('catches', function (Blueprint $table) {
            $table->dropColumn(['fishing_lesson', 'stars_received', 'lesson_confirmations_count']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['fish_points', 'stars']);
        });
    }
};
