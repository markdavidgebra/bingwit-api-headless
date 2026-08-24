<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'referral_code')) {
                $table->string('referral_code', 12)->nullable()->unique()->after('stars');
            }
            if (! Schema::hasColumn('users', 'referred_by_user_id')) {
                $table->foreignId('referred_by_user_id')
                    ->nullable()
                    ->after('referral_code')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });

        if (! DB::table('economy_settings')->where('key', 'fish_points_referral_bonus')->exists()) {
            DB::table('economy_settings')->insert([
                'key' => 'fish_points_referral_bonus',
                'value' => '25',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        User::query()
            ->whereNull('referral_code')
            ->orderBy('id')
            ->chunkById(100, function ($users) {
                foreach ($users as $user) {
                    $user->ensureReferralCode();
                }
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'referred_by_user_id')) {
                $table->dropConstrainedForeignId('referred_by_user_id');
            }
            if (Schema::hasColumn('users', 'referral_code')) {
                $table->dropUnique(['referral_code']);
                $table->dropColumn('referral_code');
            }
        });

        DB::table('economy_settings')->where('key', 'fish_points_referral_bonus')->delete();
    }
};
