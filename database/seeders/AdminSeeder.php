<?php

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        Admin::firstOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@example.com')],
            [
                'name'     => env('ADMIN_NAME', 'Administrator'),
                'password' => Hash::make(env('ADMIN_PASSWORD', 'password')),
                'role'     => 'admin',
            ]
        );

        $developer = Admin::firstOrCreate(
            ['email' => env('DEVELOPER_EMAIL', 'developer@bingwit.com')],
            [
                'name'     => env('DEVELOPER_NAME', 'Developer'),
                'password' => Hash::make(env('DEVELOPER_PASSWORD', 'password')),
                'role'     => 'developer',
            ]
        );

        if ($developer->role !== 'developer') {
            $developer->update(['role' => 'developer']);
        }
    }
}
