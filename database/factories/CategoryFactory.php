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

    public function definition(): array
    {
        $name = fake()->unique()->randomElement([
            'Rods', 'Reels', 'Bait', 'Lures', 'Hooks',
            'Fishing Lines', 'Accessories', 'Apparel', 'Boats',
            'Tackle Boxes', 'Nets', 'Coolers', 'Waders', 'Sunglasses',
        ]);

        $icons = ['🎣', '🔄', '🪱', '🎯', '🪝', '🧵', '🎒', '👕', '🚤'];

        return [
            'name'        => $name,
            'slug'        => Str::slug($name) . '-' . Str::random(5),
            'icon'        => fake()->randomElement($icons),
            'image'       => null,
            'description' => fake()->sentence(8),
            'is_active'   => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
