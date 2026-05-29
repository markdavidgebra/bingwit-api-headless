<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Rods',          'slug' => 'rods',          'icon' => '🎣', 'description' => 'Fishing rods for every style and skill level.'],
            ['name' => 'Reels',         'slug' => 'reels',         'icon' => '🔄', 'description' => 'Spinning, baitcasting, and fly reels.'],
            ['name' => 'Bait',          'slug' => 'bait',          'icon' => '🪱', 'description' => 'Live and artificial bait for all species.'],
            ['name' => 'Lures',         'slug' => 'lures',         'icon' => '🎯', 'description' => 'Hard and soft lures to attract your catch.'],
            ['name' => 'Accessories',   'slug' => 'accessories',   'icon' => '🎒', 'description' => 'Tackle boxes, tools, and fishing accessories.'],
        ];

        foreach ($categories as $category) {
            Category::firstOrCreate(
                ['slug' => $category['slug']],
                array_merge($category, ['is_active' => true])
            );
        }
    }
}
