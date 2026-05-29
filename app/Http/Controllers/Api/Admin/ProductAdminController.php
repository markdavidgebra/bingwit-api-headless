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
        $request->validate([
            'name'           => 'required|string|max:255',
            'category_id'    => 'required|exists:categories,id',
            'brand_id'       => 'nullable|exists:brands,id',
            'description'    => 'nullable|string',
            'price'          => 'required|numeric|min:0',
            'original_price' => 'nullable|numeric|min:0',
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
            'price'          => $request->price,
            'original_price' => $request->original_price,
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

        $request->validate([
            'name'           => 'sometimes|string|max:255',
            'category_id'    => 'sometimes|exists:categories,id',
            'brand_id'       => 'nullable|exists:brands,id',
            'description'    => 'nullable|string',
            'price'          => 'sometimes|numeric|min:0',
            'original_price' => 'nullable|numeric|min:0',
            'stock'          => 'sometimes|integer|min:0',
            'is_featured'    => 'nullable|boolean',
            'is_active'      => 'nullable|boolean',
        ]);

        $product->update($request->only([
            'name', 'category_id', 'brand_id',
            'description', 'price', 'original_price',
            'stock', 'condition', 'is_featured', 'is_active',
        ]));

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
        return response()->json(Category::all());
    }

    // CREATE CATEGORY
    public function createCategory(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'icon' => 'nullable|string',
        ]);

        $category = Category::create([
            'name'      => $request->name,
            'slug'      => Str::slug($request->name),
            'icon'      => $request->icon,
            'is_active' => true,
        ]);

        return response()->json([
            'message'  => 'Category created!',
            'category' => $category,
        ], 201);
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