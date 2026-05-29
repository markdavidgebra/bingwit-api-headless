<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Category;
use App\Models\Brand;
use App\Models\ProductTag;
use Illuminate\Http\Request;

class MarketplaceController extends Controller
{
    // GET MARKETPLACE HOME DATA
    public function home()
    {
        $featured   = Product::with(['category', 'brand', 'primaryImage', 'vendor'])
                              ->where('is_featured', true)
                              ->where('is_active', true)
                              ->latest()
                              ->limit(10)
                              ->get()
                              ->map(fn($p) => $this->formatProduct($p));

        $trending   = Product::with(['category', 'brand', 'primaryImage', 'vendor'])
                              ->where('is_active', true)
                              ->orderByDesc('views_count')
                              ->limit(10)
                              ->get()
                              ->map(fn($p) => $this->formatProduct($p));

        $categories = Category::where('is_active', true)
                               ->withCount('products')
                               ->get();

        $newArrivals = Product::with(['category', 'brand', 'primaryImage', 'vendor'])
                               ->where('is_active', true)
                               ->latest()
                               ->limit(10)
                               ->get()
                               ->map(fn($p) => $this->formatProduct($p));

        return response()->json([
            'featured'     => $featured,
            'trending'     => $trending,
            'categories'   => $categories,
            'new_arrivals' => $newArrivals,
        ]);
    }

    // GET ALL PRODUCTS WITH FILTERS
    public function products(Request $request)
    {
        $query = Product::with(['category', 'brand', 'primaryImage', 'vendor'])
                        ->where('is_active', true);

        // Filter by category
        if ($request->category_id) {
            $query->where('category_id', $request->category_id);
        }

        // Filter by brand
        if ($request->brand_id) {
            $query->where('brand_id', $request->brand_id);
        }

        // Filter by price range
        if ($request->min_price) {
            $query->where('price', '>=', $request->min_price);
        }
        if ($request->max_price) {
            $query->where('price', '<=', $request->max_price);
        }

        // Filter by rating
        if ($request->min_rating) {
            $query->where('rating', '>=', $request->min_rating);
        }

        // Sort
        switch ($request->sort) {
            case 'price_asc':
                $query->orderBy('price', 'asc');
                break;
            case 'price_desc':
                $query->orderBy('price', 'desc');
                break;
            case 'rating':
                $query->orderByDesc('rating');
                break;
            case 'popular':
                $query->orderByDesc('views_count');
                break;
            default:
                $query->latest();
        }

        $products = $query->paginate(15);
        $products->getCollection()->transform(
            fn($p) => $this->formatProduct($p)
        );

        return response()->json($products);
    }

    // GET SINGLE PRODUCT
    public function show(Request $request, $id)
    {
        $product = Product::with([
                        'category',
                        'brand',
                        'vendor',
                        'images',
                        'reviews.user' => function ($q) {
                            $q->select('id', 'name', 'profile_picture');
                        },
                    ])
                    ->findOrFail($id);

        // Increment views
        $product->increment('views_count');

        // Check if wishlisted by current user
        $wishlisted = false;
        if ($request->user()) {
            $wishlisted = $product->wishlists()
                                  ->where('user_id', $request->user()->id)
                                  ->exists();
        }

        // Get related products
        $related = Product::with(['primaryImage', 'vendor'])
                          ->where('category_id', $product->category_id)
                          ->where('id', '!=', $product->id)
                          ->where('is_active', true)
                          ->limit(6)
                          ->get()
                          ->map(fn($p) => $this->formatProduct($p));

        // Get catch posts using this product
        $catches = $product->catches()
                           ->with(['user' => function ($q) {
                               $q->select('id', 'name', 'profile_picture');
                           }])
                           ->withCount(['likes', 'comments'])
                           ->latest()
                           ->limit(5)
                           ->get();

        return response()->json([
            'product'    => $this->formatProduct($product),
            'wishlisted' => $wishlisted,
            'related'    => $related,
            'catches'    => $catches,
        ]);
    }

    // SEARCH PRODUCTS
    public function search(Request $request)
    {
        $request->validate([
            'query' => 'required|string|min:1|max:100',
        ]);

        $keyword  = $request->query('query');

        $products = Product::with(['category', 'brand', 'primaryImage', 'vendor'])
                           ->where('is_active', true)
                           ->where(function ($q) use ($keyword) {
                               $q->where('name', 'like', "%{$keyword}%")
                                 ->orWhere('description', 'like', "%{$keyword}%");
                           })
                           ->latest()
                           ->paginate(15);

        $products->getCollection()->transform(
            fn($p) => $this->formatProduct($p)
        );

        return response()->json($products);
    }

    // GET ALL CATEGORIES
    public function categories()
    {
        $categories = Category::where('is_active', true)
                               ->withCount('products')
                               ->get();

        return response()->json($categories);
    }

    // GET PRODUCTS BY CATEGORY
    public function byCategory($categoryId)
    {
        $category = Category::findOrFail($categoryId);

        $products = Product::with(['brand', 'primaryImage', 'vendor'])
                           ->where('category_id', $categoryId)
                           ->where('is_active', true)
                           ->latest()
                           ->paginate(15);

        $products->getCollection()->transform(
            fn($p) => $this->formatProduct($p)
        );

        return response()->json([
            'category' => $category,
            'products' => $products,
        ]);
    }

    // TAG PRODUCT IN CATCH POST
    public function tagProduct(Request $request)
    {
        $request->validate([
            'catch_id'   => 'required|exists:catches,id',
            'product_id' => 'required|exists:products,id',
        ]);

        $tag = ProductTag::firstOrCreate([
            'catch_id'   => $request->catch_id,
            'product_id' => $request->product_id,
        ]);

        return response()->json([
            'message' => 'Product tagged in catch!',
            'tag'     => $tag,
        ]);
    }

    // REMOVE PRODUCT TAG FROM CATCH
    public function removeTag(Request $request)
    {
        $request->validate([
            'catch_id'   => 'required',
            'product_id' => 'required',
        ]);

        ProductTag::where('catch_id',   $request->catch_id)
                  ->where('product_id', $request->product_id)
                  ->delete();

        return response()->json(['message' => 'Tag removed.']);
    }

    // Helper to format product data
    private function formatProduct(Product $product)
    {
        $data                      = $product->toArray();
        $data['primary_image_url'] = $product->primary_image_url;
        $data['is_on_sale']        = $product->is_on_sale;
        $data['discount_percent']  = $product->discount_percentage;

        // Format all images
        if ($product->relationLoaded('images')) {
            $data['images'] = $product->images->map(fn($img) => [
                'id'         => $img->id,
                'url'        => $img->url,
                'is_primary' => $img->is_primary,
            ]);
        }

        // Surface a compact vendor payload so the frontend can show the store
        // without leaking sensitive Authenticatable columns.
        if ($product->relationLoaded('vendor') && $product->vendor) {
            $vendor = $product->vendor;
            $data['vendor'] = [
                'id'                => $vendor->id,
                'name'              => $vendor->name,
                'store_name'        => $vendor->store_name,
                'store_slug'        => $vendor->store_slug,
                'store_logo'        => $vendor->store_logo,
                'is_verified'       => (bool) $vendor->is_verified,
            ];
        }

        return $data;
    }
}