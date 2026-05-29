<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VendorController extends Controller
{
    /**
     * Resolve the vendor context for the current request.
     *
     * - If a Vendor is authenticated, that's the vendor.
     * - If an Admin is authenticated, they may target any vendor by
     *   passing `vendor_id` (path/query/body). Without it we cannot
     *   resolve a store, so we return null.
     */
    protected function resolveVendor(Request $request): ?Vendor
    {
        $user = $request->user();

        if ($user instanceof Vendor) {
            return $user;
        }

        if ($user instanceof Admin) {
            $vendorId = $request->route('vendor_id')
                ?? $request->input('vendor_id')
                ?? $request->query('vendor_id');

            return $vendorId ? Vendor::find($vendorId) : null;
        }

        return null;
    }

    // CATALOG (read-only, for product forms)
    public function categories()
    {
        $categories = Category::where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json($categories);
    }

    public function brands()
    {
        $brands = Brand::orderBy('name')->get();

        return response()->json($brands);
    }

    // GET VENDOR DASHBOARD STATS
    public function dashboard(Request $request)
    {
        $vendor = $this->resolveVendor($request);

        if (! $vendor) {
            return response()->json([
                'message' => 'No vendor context. Admins must pass vendor_id.',
                'vendor'  => null,
            ], 404);
        }

        $productIds = $vendor->products()->pluck('id');

        $totalProducts    = $vendor->products()->count();
        $activeProducts   = $vendor->products()->where('is_active', true)->count();
        $inactiveProducts = $totalProducts - $activeProducts;

        $outOfStock = $vendor->products()
                             ->where('is_active', true)
                             ->where('stock', '<=', 0)
                             ->count();

        $lowStock = $vendor->products()
                           ->where('is_active', true)
                           ->whereBetween('stock', [1, 5])
                           ->count();

        $inventoryValue = (float) $vendor->products()
                                          ->where('is_active', true)
                                          ->selectRaw('SUM(price * stock) AS total')
                                          ->value('total');

        $totalViews     = (int) $vendor->products()->sum('views_count');
        $totalWishlists = \App\Models\Wishlist::whereIn('product_id', $productIds)->count();

        $averageRating = (float) $vendor->products()
                                         ->where('reviews_count', '>', 0)
                                         ->avg('rating');
        $totalReviews  = (int) $vendor->products()->sum('reviews_count');

        $recentProducts = $vendor->products()
                                 ->with(['category', 'primaryImage'])
                                 ->latest()
                                 ->limit(5)
                                 ->get();

        $topProducts = $vendor->products()
                              ->with(['category', 'primaryImage'])
                              ->where('is_active', true)
                              ->where('views_count', '>', 0)
                              ->orderByDesc('views_count')
                              ->limit(5)
                              ->get();

        // Items that need attention: out-of-stock first, then low-stock.
        $needsAttention = $vendor->products()
                                  ->with(['category', 'primaryImage'])
                                  ->where('is_active', true)
                                  ->where('stock', '<=', 5)
                                  ->orderBy('stock')
                                  ->limit(5)
                                  ->get();

        $setup = [
            'has_logo'        => ! empty($vendor->store_logo),
            'has_description' => ! empty($vendor->store_description),
            'has_contact'     => ! empty($vendor->contact_phone) || ! empty($vendor->contact_email ?? null),
            'has_address'     => ! empty($vendor->address),
            'has_products'    => $totalProducts > 0,
            'is_verified'     => (bool) $vendor->is_verified,
        ];

        $setupCompleted = collect($setup)->filter()->count();
        $setupTotal     = count($setup);

        return response()->json([
            'vendor'           => $vendor,
            'stats'            => [
                'total_products'    => $totalProducts,
                'active_products'   => $activeProducts,
                'inactive_products' => $inactiveProducts,
                'out_of_stock'      => $outOfStock,
                'low_stock'         => $lowStock,
                'inventory_value'   => round($inventoryValue, 2),
                'total_views'       => $totalViews,
                'total_wishlists'   => $totalWishlists,
                'average_rating'    => round($averageRating, 2),
                'total_reviews'     => $totalReviews,
            ],
            'setup'            => [
                'items'     => $setup,
                'completed' => $setupCompleted,
                'total'     => $setupTotal,
                'percent'   => $setupTotal > 0
                    ? (int) round(($setupCompleted / $setupTotal) * 100)
                    : 0,
            ],
            'recent_products'  => $recentProducts,
            'top_products'     => $topProducts,
            'needs_attention'  => $needsAttention,
        ]);
    }

    // GET MY STORE
    public function getStore(Request $request)
    {
        $vendor = $this->resolveVendor($request);

        if (! $vendor) {
            return response()->json([
                'message' => 'Vendor not found.',
                'vendor'  => null,
            ], 404);
        }

        return response()->json($vendor);
    }

    // UPDATE STORE PROFILE
    public function updateStore(Request $request)
    {
        $vendor = $this->resolveVendor($request);

        if (! $vendor) {
            return response()->json([
                'message' => 'Vendor not found.',
            ], 404);
        }

        $request->validate([
            'store_name'        => 'sometimes|string|max:255',
            'store_description' => 'nullable|string',
            'contact_phone'     => 'nullable|string',
            'address'           => 'nullable|string',
        ]);

        $vendor->update($request->only([
            'store_name',
            'store_description',
            'contact_phone',
            'address',
        ]));

        return response()->json([
            'message' => 'Store updated!',
            'vendor'  => $vendor,
        ]);
    }

    // UPLOAD / REPLACE STORE LOGO
    public function uploadLogo(Request $request)
    {
        $vendor = $this->resolveVendor($request);

        if (! $vendor) {
            return response()->json([
                'message' => 'Vendor not found.',
            ], 404);
        }

        $request->validate([
            'logo' => 'required|image|mimes:jpg,jpeg,png,webp|max:4096',
        ]);

        // Remove the previous logo if it was a stored file (not an external URL).
        if (
            $vendor->store_logo &&
            ! preg_match('#^https?://#i', $vendor->store_logo) &&
            Storage::disk('public')->exists($vendor->store_logo)
        ) {
            Storage::disk('public')->delete($vendor->store_logo);
        }

        $file     = $request->file('logo');
        $filename = 'vendor-' . $vendor->id . '-' . Str::random(12)
                  . '.' . $file->getClientOriginalExtension();

        $path = $file->storeAs('store-logos', $filename, 'public');

        $vendor->store_logo = $path;
        $vendor->save();

        return response()->json([
            'message' => 'Store logo updated!',
            'vendor'  => $vendor->fresh(),
        ]);
    }

    // REMOVE STORE LOGO
    public function removeLogo(Request $request)
    {
        $vendor = $this->resolveVendor($request);

        if (! $vendor) {
            return response()->json([
                'message' => 'Vendor not found.',
            ], 404);
        }

        if (
            $vendor->store_logo &&
            ! preg_match('#^https?://#i', $vendor->store_logo) &&
            Storage::disk('public')->exists($vendor->store_logo)
        ) {
            Storage::disk('public')->delete($vendor->store_logo);
        }

        $vendor->store_logo = null;
        $vendor->save();

        return response()->json([
            'message' => 'Store logo removed.',
            'vendor'  => $vendor->fresh(),
        ]);
    }

    // GET MY PRODUCTS
    public function myProducts(Request $request)
    {
        $vendor = $this->resolveVendor($request);

        if (! $vendor) {
            return response()->json(['data' => []]);
        }

        $products = $vendor->products()
                           ->with(['category', 'brand', 'primaryImage'])
                           ->latest()
                           ->paginate(20);

        return response()->json($products);
    }

    // CREATE PRODUCT
    public function createProduct(Request $request)
    {
        $vendor = $this->resolveVendor($request);

        if (! $vendor) {
            return response()->json([
                'message' => 'You need a vendor context to add products.',
            ], 403);
        }

        $request->validate([
            'name'           => 'required|string|max:255',
            'category_id'    => 'required|exists:categories,id',
            'brand_id'       => 'nullable|exists:brands,id',
            'description'    => 'nullable|string',
            'price'          => 'required|numeric|min:0',
            'original_price' => 'nullable|numeric|min:0',
            'stock'          => 'required|integer|min:0',
            'condition'      => 'nullable|in:new,used',
        ]);

        $product = Product::create([
            'vendor_id'      => $vendor->id,
            'name'           => $request->name,
            'slug'           => Str::slug($request->name) . '-' . time(),
            'category_id'    => $request->category_id,
            'brand_id'       => $request->brand_id,
            'description'    => $request->description,
            'price'          => $request->price,
            'original_price' => $request->original_price,
            'stock'          => $request->stock,
            'condition'      => $request->condition ?? 'new',
            'is_active'      => true,
        ]);

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
            'product' => $product->load(['category', 'brand']),
        ], 201);
    }

    // UPDATE PRODUCT
    public function updateProduct(Request $request, $id)
    {
        $product = $this->findOwnedProduct($request, $id);

        $request->validate([
            'name'           => 'sometimes|string|max:255',
            'category_id'    => 'sometimes|exists:categories,id',
            'brand_id'       => 'nullable|exists:brands,id',
            'description'    => 'nullable|string',
            'price'          => 'sometimes|numeric|min:0',
            'original_price' => 'nullable|numeric|min:0',
            'stock'          => 'sometimes|integer|min:0',
            'is_active'      => 'nullable|boolean',
        ]);

        $product->update($request->only([
            'name', 'category_id', 'brand_id',
            'description', 'price', 'original_price',
            'stock', 'condition', 'is_active',
        ]));

        return response()->json([
            'message' => 'Product updated!',
            'product' => $product,
        ]);
    }

    // DELETE PRODUCT
    public function deleteProduct(Request $request, $id)
    {
        $product = $this->findOwnedProduct($request, $id);

        foreach ($product->images as $image) {
            Storage::disk('public')->delete($image->image_path);
        }

        $product->delete();

        return response()->json(['message' => 'Product deleted.']);
    }

    // TOGGLE PRODUCT STATUS
    public function toggleProduct(Request $request, $id)
    {
        $product = $this->findOwnedProduct($request, $id);

        $product->update(['is_active' => ! $product->is_active]);

        return response()->json([
            'message'   => $product->is_active
                ? 'Product activated!'
                : 'Product deactivated.',
            'is_active' => $product->is_active,
        ]);
    }

    /**
     * Find a product owned by the current vendor context.
     * Admins may operate on any vendor's product when scoped.
     */
    protected function findOwnedProduct(Request $request, $id): Product
    {
        $vendor = $this->resolveVendor($request);
        $query  = Product::where('id', $id);

        // Admin acting without a vendor_id can touch any product;
        // vendors and scoped admins are limited to their vendor.
        if ($vendor) {
            $query->where('vendor_id', $vendor->id);
        } elseif (! ($request->user() instanceof Admin)) {
            abort(403, 'Vendor context required.');
        }

        return $query->firstOrFail();
    }
}
