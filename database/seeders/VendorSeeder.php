<?php

namespace Database\Seeders;

use App\Models\Vendor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class VendorSeeder extends Seeder
{
    public function run(): void
    {
        Vendor::firstOrCreate(
            ['email' => env('VENDOR_EMAIL', 'vendor@example.com')],
            [
                'name'       => env('VENDOR_NAME', 'Sample Vendor'),
                'password'   => Hash::make(env('VENDOR_PASSWORD', 'password')),
                'store_name' => env('VENDOR_STORE_NAME', 'Sample Tackle Shop'),
                'store_slug' => Str::slug(env('VENDOR_STORE_NAME', 'Sample Tackle Shop')) . '-' . Str::random(6),
                'is_active'  => true,
                'is_verified'=> true,
            ]
        );
    }
}
