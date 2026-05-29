<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    private static int $sequence = 0;

    public function definition(): array
    {
        $names = [
            'Rods', 'Reels', 'Bait', 'Lures', 'Hooks',
            'Fishing Lines', 'Accessories', 'Apparel', 'Boats',
            'Tackle Boxes', 'Nets', 'Coolers', 'Waders', 'Sunglasses',
        ];

        $icons = ['🎣', '🔄', '🪱', '🎯', '🪝', '🧵', '🎒', '👕', '🚤'];

        $name = $names[static::$sequence % count($names)];
        static::$sequence++;

        return [
            'name'        => $name,
            'slug'        => Str::slug($name) . '-' . Str::random(5),
            'icon'        => $icons[array_rand($icons)],
            'image'       => null,
            'description' => 'Fishing category for ' . strtolower($name) . '.',
            'is_active'   => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
