<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_roles', function (Blueprint $table) {
            if (! Schema::hasColumn('staff_roles', 'permissions')) {
                $table->json('permissions')->nullable()->after('description');
            }
        });

        $admin = [
            'dashboard', 'users', 'catches', 'reports', 'account-deletion',
            'products', 'categories', 'fish-points', 'affiliates',
            'tournaments', 'fishing-boats', 'vendors', 'delivery',
            'leaderboard', 'spots', 'municipalities', 'resorts', 'notifications',
        ];
        $developer = array_merge(['staff', 'roles'], $admin);

        DB::table('staff_roles')->where('slug', 'admin')->update([
            'permissions' => json_encode($admin),
            'updated_at' => now(),
        ]);
        DB::table('staff_roles')->where('slug', 'developer')->update([
            'permissions' => json_encode($developer),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('staff_roles', function (Blueprint $table) {
            if (Schema::hasColumn('staff_roles', 'permissions')) {
                $table->dropColumn('permissions');
            }
        });
    }
};
