<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Category;
use App\Models\Brand;
use App\Models\Notification;
use App\Models\ProductClaim;
use App\Models\ProductTag;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MarketplaceController extends Controller
{
    public function __construct(private WalletService $wallet)
    {
    }

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

    /** Claim a marketplace product with Stars (cost set by admin). */
    public function claim(Request $request, $id)
    {
        $product = Product::where('is_active', true)->findOrFail($id);

        $starCost = (int) $product->star_cost;
        if ($starCost < 1) {
            return response()->json([
                'message' => 'This item does not have a Star cost yet. An admin must assign one.',
            ], 422);
        }

        if ((int) $product->stock < 1) {
            return response()->json(['message' => 'This item is out of stock.'], 422);
        }

        try {
            DB::transaction(function () use ($request, $product) {
                $locked = Product::where('id', $product->id)->lockForUpdate()->first();

                if ((int) $locked->stock < 1) {
                    throw new \RuntimeException('This item is out of stock.');
                }

                if ((int) $locked->star_cost < 1) {
                    throw new \RuntimeException('This item does not have a Star cost yet.');
                }

                $this->wallet->spendStars(
                    $request->user(),
                    (int) $locked->star_cost,
                    'product_claim',
                    'product',
                    (int) $locked->id,
                    'Claimed: ' . $locked->name
                );

                $locked->decrement('stock');

                ProductClaim::create([
                    'user_id'     => $request->user()->id,
                    'product_id'  => $locked->id,
                    'stars_spent' => (int) $locked->star_cost,
                    'status'      => 'pending',
                ]);
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        Notification::create([
            'user_id'        => $request->user()->id,
            'type'           => 'redemption',
            'title'          => 'Claim submitted!',
            'body'           => 'You claimed ' . $product->name . " for {$starCost} Stars.",
            'reference_id'   => $product->id,
            'reference_type' => 'product',
        ]);

        return response()->json([
            'message'     => 'Claimed with Stars! The store will process your item.',
            'your_stars'  => $request->user()->fresh()->stars,
            'stars_spent' => $starCost,
        ], 201);
    }

    public function myClaims(Request $request)
    {
        $rows = ProductClaim::with('product.primaryImage')
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get();

        return response()->json(['claims' => $rows]);
    }

    // TAG PRODUCT IN CATCH POST
    public function tagProduct(Request $request)
    {
        $request->validate([
            'catch_id'   => 'required|exists:catches,id',
            'product_id' => 'required|exists:products,id',
        ]);

        $catch = \App\Models\FishCatch::findOrFail($request->catch_id);

        if ($catch->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'You can only tag products on your own catches.',
            ], 403);
        }

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
            'catch_id'   => 'required|exists:catches,id',
            'product_id' => 'required|exists:products,id',
        ]);

        $catch = \App\Models\FishCatch::findOrFail($request->catch_id);

        if ($catch->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'You can only remove tags from your own catches.',
            ], 403);
        }

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
        $data['star_cost']         = $product->star_cost !== null ? (int) $product->star_cost : null;
        $data['is_points_only']    = $product->isClaimableWithStars();
        if ($data['is_points_only']) {
            $data['price'] = 0;
            $data['original_price'] = null;
            $data['is_on_sale'] = false;
            $data['discount_percent'] = 0;
        }

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
                'city'              => $vendor->city,
                'province'          => $vendor->province,
                'island_group'      => $vendor->island_group,
                'local_area'        => $vendor->local_area,
                'address'           => $vendor->address,
            ];
        }

        return $data;
    }
}