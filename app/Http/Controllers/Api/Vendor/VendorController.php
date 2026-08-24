<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Brand;
use App\Models\Category;
use App\Models\MerchantGift;
use App\Models\Notification;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductOrder;
use App\Models\User;
use App\Models\Vendor;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VendorController extends Controller
{
    public function __construct(private WalletService $wallet)
    {
    }

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

        $query = $vendor->products()
                           ->with(['category', 'brand', 'primaryImage', 'images']);

        $stock = $request->query('stock');
        if ($stock === 'out') {
            $query->where('stock', '<=', 0);
        } elseif ($stock === 'low') {
            $query->whereBetween('stock', [1, 5]);
        } elseif ($stock === 'attention') {
            $query->where('stock', '<=', 5);
        } elseif ($stock === 'ok') {
            $query->where('stock', '>', 5);
        }

        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);

        if (in_array($stock, ['out', 'low', 'attention'], true)) {
            $query->orderBy('stock')->orderByDesc('updated_at');
        } else {
            $query->latest();
        }

        $products = $query->paginate($perPage);

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
            'images'         => 'nullable|array|max:8',
            'images.*'       => 'image|mimes:jpg,jpeg,png,webp|max:4096',
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
            'product' => $product->load(['category', 'brand', 'images']),
        ], 201);
    }

    // ADD MORE IMAGES TO AN EXISTING PRODUCT
    public function addProductImages(Request $request, $id)
    {
        $product = $this->findOwnedProduct($request, $id);

        $request->validate([
            'images'   => 'required|array|max:8',
            'images.*' => 'image|mimes:jpg,jpeg,png,webp|max:4096',
        ]);

        $startOrder = (int) $product->images()->max('sort_order') + 1;
        $hasPrimary = $product->images()->where('is_primary', true)->exists();

        $created = [];
        foreach ($request->file('images') as $index => $image) {
            $path = $image->store('products', 'public');
            $created[] = ProductImage::create([
                'product_id' => $product->id,
                'image_path' => $path,
                'is_primary' => ! $hasPrimary && $index === 0,
                'sort_order' => $startOrder + $index,
            ]);

            if (! $hasPrimary && $index === 0) {
                $hasPrimary = true;
            }
        }

        return response()->json([
            'message' => 'Images added.',
            'images'  => $created,
        ], 201);
    }

    // DELETE A SINGLE IMAGE FROM A PRODUCT
    public function deleteProductImage(Request $request, $id, $imageId)
    {
        $product = $this->findOwnedProduct($request, $id);

        $image = $product->images()->where('id', $imageId)->firstOrFail();
        $wasPrimary = (bool) $image->is_primary;

        if (
            $image->image_path &&
            ! preg_match('#^https?://#i', $image->image_path) &&
            Storage::disk('public')->exists($image->image_path)
        ) {
            Storage::disk('public')->delete($image->image_path);
        }
        $image->delete();

        // If the primary image was removed, promote the next one.
        if ($wasPrimary) {
            $next = $product->images()->orderBy('sort_order')->first();
            if ($next) {
                $next->update(['is_primary' => true]);
            }
        }

        return response()->json([
            'message' => 'Image deleted.',
            'id'      => (int) $imageId,
        ]);
    }

    // SET AN IMAGE AS THE PRODUCT'S PRIMARY
    public function setPrimaryProductImage(Request $request, $id, $imageId)
    {
        $product = $this->findOwnedProduct($request, $id);

        $image = $product->images()->where('id', $imageId)->firstOrFail();

        $product->images()->update(['is_primary' => false]);
        $image->update(['is_primary' => true]);

        return response()->json([
            'message' => 'Primary image updated.',
            'id'      => (int) $imageId,
        ]);
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

    /** Search anglers so the store can award Stars. */
    public function searchAnglers(Request $request)
    {
        $request->validate([
            'search' => 'required|string|min:2|max:80',
        ]);

        $term = trim((string) $request->query('search'));

        $users = User::query()
            ->where(function ($query) use ($term) {
                $query->where('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%");
            })
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'email', 'fish_points', 'stars', 'profile_picture']);

        return response()->json(['users' => $users]);
    }

    /** Store awards Fish Points to a customer. Anglers convert FP → Stars, then claim. */
    public function grantStars(Request $request)
    {
        $vendor = $this->resolveVendor($request);

        if (! $vendor) {
            return response()->json([
                'message' => 'You need a store context to give Fish Points.',
            ], 403);
        }

        $data = $request->validate([
            'user_id'     => 'required|exists:users,id',
            'fish_points' => 'nullable|integer|min:1|max:10000',
            'stars'       => 'nullable|integer|min:1|max:10000',
            'note'        => 'nullable|string|max:500',
        ]);

        $points = (int) ($data['fish_points'] ?? $data['stars'] ?? 0);
        if ($points < 1) {
            return response()->json([
                'message' => 'Enter how many Fish Points to give.',
            ], 422);
        }

        $user = User::findOrFail($data['user_id']);
        $note = $data['note'] ?: ('Fish Points from ' . ($vendor->store_name ?: $vendor->name));

        $this->wallet->creditFishPoints(
            $user,
            $points,
            'store_grant',
            'vendor',
            (int) $vendor->id,
            $note
        );

        Notification::create([
            'user_id'        => $user->id,
            'type'           => 'fish_points_gift',
            'title'          => 'Fish Points from a store',
            'body'           => ($vendor->store_name ?: $vendor->name) . " gave you {$points} Fish Points.",
            'reference_id'   => $vendor->id,
            'reference_type' => 'vendor',
        ]);

        $fresh = $user->fresh();

        return response()->json([
            'message' => "Gave {$points} Fish Points to {$user->name}.",
            'user'    => [
                'id'          => $user->id,
                'name'        => $user->name,
                'fish_points' => $fresh->fish_points,
                'stars'       => $fresh->stars,
            ],
        ]);
    }

    // GET GIFTS SENT TO THIS STORE
    public function gifts(Request $request)
    {
        $vendor = $this->resolveVendor($request);

        if (! $vendor) {
            return response()->json(['data' => []]);
        }

        $perPage = min(max((int) $request->query('per_page', 25), 1), 100);

        $gifts = MerchantGift::query()
            ->where('vendor_id', $vendor->id)
            ->with([
                'sender:id,name',
                'catalogItem:id,name,emoji,fish_points_cost',
            ])
            ->latest()
            ->paginate($perPage);

        return response()->json($gifts);
    }

    public function orders(Request $request)
    {
        $vendor = $this->resolveVendor($request);

        if (! $vendor) {
            return response()->json(['data' => []]);
        }

        $perPage = min(max((int) $request->query('per_page', 25), 1), 100);

        $query = ProductOrder::query()
            ->where('vendor_id', $vendor->id)
            ->with([
                'user:id,name',
                'product:id,name',
            ]);

        $filter = (string) $request->query('filter', 'all');
        if ($filter === 'delivery') {
            $query->where('fulfillment', 'delivery');
        } elseif ($filter === 'pickup') {
            $query->where('fulfillment', 'pickup');
        } elseif ($filter === 'done') {
            $query->where(function ($q) {
                $q->where('status', 'fulfilled')
                    ->orWhereIn('shipping_status', ['delivered', 'picked_up']);
            });
        } elseif ($filter === 'active') {
            $query->where(function ($q) {
                $q->whereIn('status', ['pending', 'paid'])
                    ->where(function ($inner) {
                        $inner->whereNull('shipping_status')
                            ->orWhereNotIn('shipping_status', ['delivered', 'picked_up']);
                    });
            });
        }

        $orders = $query->latest()->paginate($perPage);

        return response()->json($orders);
    }

    public function updateOrderShipping(Request $request, $id)
    {
        $vendor = $this->resolveVendor($request);

        if (! $vendor) {
            return response()->json(['message' => 'Vendor context required.'], 403);
        }

        $data = $request->validate([
            'shipping_status' => 'required|string|in:processing,packed,out_for_delivery,delivered,ready_for_pickup,picked_up',
        ]);

        $order = ProductOrder::with('product')
            ->where('vendor_id', $vendor->id)
            ->findOrFail($id);

        try {
            $order->setShippingStatus($data['shipping_status']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Order status updated.',
            'order' => $order->fresh(),
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
