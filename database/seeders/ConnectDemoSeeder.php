<?php

namespace Database\Seeders;

use App\Models\Municipality;
use App\Models\Resort;
use Illuminate\Database\Seeder;

class ConnectDemoSeeder extends Seeder
{
    public function run(): void
    {
        $m = Municipality::firstOrCreate(
            ['name' => 'Davao City', 'province' => 'Davao del Sur'],
            ['region' => 'XI', 'is_active' => true, 'is_verified' => true]
        );

        Resort::firstOrCreate(
            ['name' => 'Samal Island Fishing Resort'],
            [
                'description' => 'Fishing area with gear for rent. Reviews from local anglers welcome.',
                'location' => 'Samal Island, Davao',
                'municipality_id' => $m->id,
                'latitude' => 7.0731,
                'longitude' => 125.7631,
                'has_fishing_area' => true,
                'has_gear_rental' => true,
                'is_verified' => true,
                'is_active' => true,
                'rating' => 4.5,
                'reviews_count' => 0,
            ]
        );
    }
}
