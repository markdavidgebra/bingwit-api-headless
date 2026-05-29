<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Category;
use App\Models\Brand;
use App\Models\Product;
use Illuminate\Support\Str;

class MarketplaceSeeder extends Seeder
{
    public function run(): void
    {
        // Categories
        $categories = [
            ['name' => 'Rods',          'icon' => '🎣', 'slug' => 'rods'],
            ['name' => 'Reels',         'icon' => '🔄', 'slug' => 'reels'],
            ['name' => 'Bait',          'icon' => '🪱', 'slug' => 'bait'],
            ['name' => 'Lures',         'icon' => '🎯', 'slug' => 'lures'],
            ['name' => 'Hooks',         'icon' => '🪝', 'slug' => 'hooks'],
            ['name' => 'Fishing Lines', 'icon' => '🧵', 'slug' => 'fishing-lines'],
            ['name' => 'Accessories',   'icon' => '🎒', 'slug' => 'accessories'],
            ['name' => 'Apparel',       'icon' => '👕', 'slug' => 'apparel'],
            ['name' => 'Boats',         'icon' => '🚤', 'slug' => 'boats'],
        ];

        foreach ($categories as $cat) {
            Category::firstOrCreate(
                ['slug' => $cat['slug']],
                array_merge($cat, ['is_active' => true])
            );
        }

        // Brands
        $brands = [
            ['name' => 'Shimano',  'slug' => 'shimano'],
            ['name' => 'Daiwa',    'slug' => 'daiwa'],
            ['name' => 'Rapala',   'slug' => 'rapala'],
            ['name' => 'Berkley',  'slug' => 'berkley'],
            ['name' => 'Penn',     'slug' => 'penn'],
        ];

        foreach ($brands as $brand) {
            Brand::firstOrCreate(
                ['slug' => $brand['slug']],
                array_merge($brand, ['is_active' => true])
            );
        }

        // Sample Products
        $rodsCategory  = Category::where('slug', 'rods')->first();
        $reelsCategory = Category::where('slug', 'reels')->first();
        $luresCategory = Category::where('slug', 'lures')->first();
        $shimano       = Brand::where('slug', 'shimano')->first();
        $daiwa         = Brand::where('slug', 'daiwa')->first();
        $rapala        = Brand::where('slug', 'rapala')->first();

        $products = [
            [
                'name'           => 'Shimano Stradic FL 2500',
                'category_id'    => $reelsCategory->id,
                'brand_id'       => $shimano->id,
                'description'    => 'Premium spinning reel with smooth drag system. Perfect for freshwater and light saltwater fishing.',
                'price'          => 4500.00,
                'original_price' => 5500.00,
                'stock'          => 10,
                'is_featured'    => true,
            ],
            [
                'name'           => 'Daiwa Laguna 7ft Rod',
                'category_id'    => $rodsCategory->id,
                'brand_id'       => $daiwa->id,
                'description'    => 'Medium-heavy spinning rod ideal for bass and tilapia fishing.',
                'price'          => 2800.00,
                'original_price' => null,
                'stock'          => 15,
                'is_featured'    => true,
            ],
            [
                'name'           => 'Rapala Original Floater F11',
                'category_id'    => $luresCategory->id,
                'brand_id'       => $rapala->id,
                'description'    => 'Classic floating lure. Effective for bass, snook, and other predatory fish.',
                'price'          => 350.00,
                'original_price' => 450.00,
                'stock'          => 50,
                'is_featured'    => true,
            ],
            [
                'name'           => 'Shimano Sienna FE 1000',
                'category_id'    => $reelsCategory->id,
                'brand_id'       => $shimano->id,
                'description'    => 'Entry-level spinning reel with smooth operation.',
                'price'          => 1200.00,
                'original_price' => null,
                'stock'          => 20,
                'is_featured'    => false,
            ],
            [
                'name'           => 'Daiwa Ninja 9ft Spinning Rod',
                'category_id'    => $rodsCategory->id,
                'brand_id'       => $daiwa->id,
                'description'    => 'Versatile rod for both freshwater and saltwater fishing.',
                'price'          => 3200.00,
                'original_price' => 3800.00,
                'stock'          => 8,
                'is_featured'    => false,
            ],
        ];

        foreach ($products as $productData) {
            Product::firstOrCreate(
                ['slug' => Str::slug($productData['name']) . '-' . time()],
                array_merge($productData, [
                    'slug'      => Str::slug($productData['name']),
                    'condition' => 'new',
                    'is_active' => true,
                ])
            );
        }

        echo "Marketplace data seeded!\n";
    }
}