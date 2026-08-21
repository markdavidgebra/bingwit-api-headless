<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('municipalities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('province')->nullable();
            $table->string('region')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_verified')->default(false);
            $table->timestamps();
            $table->unique(['name', 'province']);
        });

        Schema::create('direct_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['sender_id', 'recipient_id']);
            $table->index(['recipient_id', 'sender_id']);
        });

        Schema::create('resorts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->foreignId('municipality_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('contact_email')->nullable();
            $table->boolean('has_fishing_area')->default(true);
            $table->boolean('has_gear_rental')->default(false);
            $table->boolean('is_verified')->default(false);
            $table->boolean('is_active')->default(true);
            $table->decimal('rating', 3, 2)->default(0);
            $table->unsignedInteger('reviews_count')->default(0);
            $table->timestamps();
        });

        Schema::create('resort_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resort_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('body')->nullable();
            $table->timestamps();
            $table->unique(['resort_id', 'user_id']);
        });

        if (Schema::hasTable('fishing_spots') && ! Schema::hasColumn('fishing_spots', 'municipality_id')) {
            Schema::table('fishing_spots', function (Blueprint $table) {
                $table->foreignId('municipality_id')->nullable()->after('spot_type')->constrained()->nullOnDelete();
            });
        }

        if (Schema::hasTable('fishing_boats') && ! Schema::hasColumn('fishing_boats', 'municipality_id')) {
            Schema::table('fishing_boats', function (Blueprint $table) {
                $table->foreignId('municipality_id')->nullable()->after('location')->constrained()->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('fishing_boats') && Schema::hasColumn('fishing_boats', 'municipality_id')) {
            Schema::table('fishing_boats', function (Blueprint $table) {
                $table->dropConstrainedForeignId('municipality_id');
            });
        }

        if (Schema::hasTable('fishing_spots') && Schema::hasColumn('fishing_spots', 'municipality_id')) {
            Schema::table('fishing_spots', function (Blueprint $table) {
                $table->dropConstrainedForeignId('municipality_id');
            });
        }

        Schema::dropIfExists('resort_reviews');
        Schema::dropIfExists('resorts');
        Schema::dropIfExists('direct_messages');
        Schema::dropIfExists('municipalities');
    }
};
