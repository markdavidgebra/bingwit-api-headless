<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Category;
use App\Models\Brand;
use App\Models\ProductImage;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class ProductAdminController extends Controller
{
    // GET ALL PRODUCTS FOR ADMIN
    public function index(Request $request)
    {
        $products = Product::with(['category', 'brand', 'primaryImage'])
                           ->latest()
                           ->paginate(20);

        return response()->json($products);
    }

    // CREATE PRODUCT
    public function store(Request $request)
    {
        $starCost = $request->filled('star_cost') ? (int) $request->star_cost : null;
        $starsOnly = $starCost !== null && $starCost >= 1;

        $request->validate([
            'name'           => 'required|string|max:255',
            'category_id'    => 'required|exists:categories,id',
            'brand_id'       => 'nullable|exists:brands,id',
            'description'    => 'nullable|string',
            'price'          => $starsOnly ? 'nullable|numeric|min:0' : 'required|numeric|min:0',
            'original_price' => 'nullable|numeric|min:0',
            'star_cost'      => 'nullable|integer|min:1',
            'stock'          => 'required|integer|min:0',
            'condition'      => 'nullable|in:new,used',
            'is_featured'    => 'nullable|boolean',
        ]);

        $product = Product::create([
            'name'           => $request->name,
            'slug'           => Str::slug($request->name) . '-' . time(),
            'category_id'    => $request->category_id,
            'brand_id'       => $request->brand_id,
            'description'    => $request->description,
            'price'          => $starsOnly ? 0 : $request->price,
            'original_price' => $starsOnly ? null : $request->original_price,
            'star_cost'      => $starsOnly ? $starCost : null,
            'is_points_only' => $starsOnly,
            'stock'          => $request->stock,
            'condition'      => $request->condition ?? 'new',
            'is_featured'    => $request->is_featured ?? false,
            'is_active'      => true,
        ]);

        // Upload images if provided
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $index => $image) {
                $path = $image->store('products', 'public');
                ProductImage::create([
                    'product_id' => $product->id,
                    'image_path' => $path,
                    'is_primary' => $index === 0,
                    'sort_order' => $index,
                ]);
            }
        }

        return response()->json([
            'message' => 'Product created!',
            'product' => $product->load(['category', 'brand', 'images']),
        ], 201);
    }

    // UPDATE PRODUCT
    public function update(Request $request, $id)
    {
        $product = Product::findOrFail($id);

        $starCost = $request->exists('star_cost')
            ? ($request->filled('star_cost') ? (int) $request->star_cost : null)
            : ($product->star_cost !== null ? (int) $product->star_cost : null);
        $starsOnly = $starCost !== null && $starCost >= 1;

        $request->validate([
            'name'           => 'sometimes|string|max:255',
            'category_id'    => 'sometimes|exists:categories,id',
            'brand_id'       => 'nullable|exists:brands,id',
            'description'    => 'nullable|string',
            'price'          => $starsOnly ? 'nullable|numeric|min:0' : 'sometimes|numeric|min:0',
            'original_price' => 'nullable|numeric|min:0',
            'star_cost'      => 'nullable|integer|min:1',
            'stock'          => 'sometimes|integer|min:0',
            'is_featured'    => 'nullable|boolean',
            'is_active'      => 'nullable|boolean',
        ]);

        $payload = $request->only([
            'name', 'category_id', 'brand_id',
            'description',
            'stock', 'condition', 'is_featured', 'is_active',
        ]);

        $payload['star_cost'] = $starsOnly ? $starCost : null;
        $payload['is_points_only'] = $starsOnly;
        if ($starsOnly) {
            $payload['price'] = 0;
            $payload['original_price'] = null;
        } else {
            if ($request->exists('price')) {
                $payload['price'] = $request->price;
            }
            if ($request->exists('original_price')) {
                $payload['original_price'] = $request->original_price;
            }
        }

        $product->update($payload);

        return response()->json([
            'message' => 'Product updated!',
            'product' => $product,
        ]);
    }

    // DELETE PRODUCT
    public function destroy($id)
    {
        $product = Product::findOrFail($id);

        // Delete images from storage
        foreach ($product->images as $image) {
            Storage::disk('public')->delete($image->image_path);
        }

        $product->delete();

        return response()->json(['message' => 'Product deleted.']);
    }

    // GET ALL CATEGORIES
    public function categories()
    {
        return response()->json(
            Category::withCount('products')->orderBy('name')->get()
        );
    }

    // CREATE CATEGORY
    public function createCategory(Request $request)
    {
        $request->validate([
            'name'        => 'required|string|max:255|unique:categories,name',
            'icon'        => 'nullable|string|max:32',
            'description' => 'nullable|string|max:1000',
            'is_active'   => 'nullable|boolean',
        ]);

        $category = Category::create([
            'name'        => $request->name,
            'slug'        => $this->uniqueCategorySlug($request->name),
            'icon'        => $request->icon,
            'description' => $request->description,
            'is_active'   => $request->boolean('is_active', true),
        ]);

        return response()->json([
            'message'  => 'Category created!',
            'category' => $category->loadCount('products'),
        ], 201);
    }

    // UPDATE CATEGORY
    public function updateCategory(Request $request, $id)
    {
        $category = Category::findOrFail($id);

        $request->validate([
            'name'        => 'sometimes|required|string|max:255|unique:categories,name,' . $category->id,
            'icon'        => 'nullable|string|max:32',
            'description' => 'nullable|string|max:1000',
            'is_active'   => 'nullable|boolean',
        ]);

        $payload = $request->only(['name', 'icon', 'description']);
        if ($request->has('is_active')) {
            $payload['is_active'] = $request->boolean('is_active');
        }
        if ($request->filled('name') && $request->name !== $category->name) {
            $payload['slug'] = $this->uniqueCategorySlug($request->name, $category->id);
        }

        $category->update($payload);

        return response()->json([
            'message'  => 'Category updated!',
            'category' => $category->fresh()->loadCount('products'),
        ]);
    }

    // DELETE CATEGORY
    public function destroyCategory($id)
    {
        $category = Category::withCount('products')->findOrFail($id);

        if ($category->products_count > 0) {
            return response()->json([
                'message' => "Cannot delete “{$category->name}” because {$category->products_count} product(s) still use it. Move or delete those products first.",
            ], 409);
        }

        $category->delete();

        return response()->json(['message' => 'Category deleted.']);
    }

    private function uniqueCategorySlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'category';
        $slug = $base;
        $i = 1;

        while (
            Category::where('slug', $slug)
                ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }

    // GET ALL BRANDS
    public function brands()
    {
        return response()->json(Brand::all());
    }

    // CREATE BRAND
    public function createBrand(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $brand = Brand::create([
            'name'      => $request->name,
            'slug'      => Str::slug($request->name),
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'Brand created!',
            'brand'   => $brand,
        ], 201);
    }

    // TOGGLE FEATURE STATUS
    public function toggleFeature($id)
    {
        $product = Product::findOrFail($id);
        $product->update(['is_featured' => !$product->is_featured]);

        return response()->json([
            'message'     => $product->is_featured
                ? 'Product featured!'
                : 'Product unfeatured.',
            'is_featured' => $product->is_featured,
        ]);
    }
}