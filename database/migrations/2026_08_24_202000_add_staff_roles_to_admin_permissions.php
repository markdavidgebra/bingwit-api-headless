<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $row = DB::table('staff_roles')->where('slug', 'admin')->first();
        if (! $row) {
            return;
        }

        $permissions = json_decode($row->permissions ?? '[]', true);
        if (! is_array($permissions)) {
            $permissions = [];
        }

        $permissions = array_values(array_unique(array_merge($permissions, ['staff', 'roles'])));

        DB::table('staff_roles')->where('slug', 'admin')->update([
            'permissions' => json_encode($permissions),
            'updated_at'  => now(),
        ]);
    }

    public function down(): void
    {
        $row = DB::table('staff_roles')->where('slug', 'admin')->first();
        if (! $row) {
            return;
        }

        $permissions = json_decode($row->permissions ?? '[]', true);
        if (! is_array($permissions)) {
            return;
        }

        DB::table('staff_roles')->where('slug', 'admin')->update([
            'permissions' => json_encode(array_values(array_diff($permissions, ['staff', 'roles']))),
            'updated_at'  => now(),
        ]);
    }
};
