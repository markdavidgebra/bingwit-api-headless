<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_roles', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        DB::table('staff_roles')->insert([
            [
                'slug' => 'developer',
                'name' => 'Developer',
                'description' => 'Full access, including staff and roles.',
                'is_system' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'slug' => 'admin',
                'name' => 'Admin',
                'description' => 'Standard Bingwit admin console.',
                'is_system' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_roles');
    }
};
