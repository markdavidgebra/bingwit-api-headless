<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\CatchController;
use App\Http\Controllers\Api\FeedController;
use App\Http\Controllers\Api\ForecastController;
use App\Http\Controllers\Api\MapController;
use App\Http\Controllers\Api\LeaderboardController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\GroupController;
use App\Http\Controllers\Api\MarketplaceController;
use App\Http\Controllers\Api\WishlistController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\Admin\ProductAdminController;
use App\Http\Controllers\Api\Vendor\VendorController;
use App\Http\Controllers\Api\Admin\VendorAdminController;
use App\Http\Controllers\Api\Admin\AdminProfileController;
// ─── Public Routes ────────────────────────────────────────

// ─── Marketplace (Public) ─────────────────────────────────
Route::get('/marketplace', [MarketplaceController::class, 'home']);
Route::get('/marketplace/products', [MarketplaceController::class, 'products']);
Route::get('/marketplace/products/{id}', [MarketplaceController::class, 'show']);
Route::get('/marketplace/search', [MarketplaceController::class, 'search']);
Route::get('/marketplace/categories', [MarketplaceController::class, 'categories']);
Route::get('/marketplace/categories/{id}', [MarketplaceController::class, 'byCategory']);
Route::get('/marketplace/products/{id}/reviews', [ReviewController::class, 'index']);

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/vendor/login', [AuthController::class, 'loginVendor']);
Route::post('/admin/login', [AuthController::class, 'loginAdmin']);

// Forecast
Route::get('/forecast', [ForecastController::class, 'getForecast']);

// Feed
Route::get('/feed', [FeedController::class, 'global']);
Route::get('/feed/search', [FeedController::class, 'search']);

// Map
Route::get('/map/spots', [MapController::class, 'spots']);
Route::get('/map/nearby-catches', [MapController::class, 'nearbyCatches']);

// Leaderboard
Route::get('/leaderboard/biggest', [LeaderboardController::class, 'biggestCatch']);
Route::get('/leaderboard/most', [LeaderboardController::class, 'mostCatches']);
Route::get('/leaderboard/all-time-biggest', [LeaderboardController::class, 'allTimeBiggest']);
Route::get('/leaderboard/all-time-most', [LeaderboardController::class, 'allTimeMost']);

// Profiles & Catches
Route::get('/users/{id}', [ProfileController::class, 'show']);
Route::get('/users/{id}/catches', [CatchController::class, 'userCatches']);
Route::get('/catches/{id}', [CatchController::class, 'show']);

// Groups 
Route::get('/groups', [GroupController::class, 'index']);
Route::get('/groups/{id}', [GroupController::class, 'show']);
Route::get('/groups/{id}/posts', [GroupController::class, 'posts']);

// ─── Protected Routes ─────────────────────────────────────

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/users', [ProfileController::class, 'allUsers']);

    // ─── Vendor Routes (Vendor or Admin) ──────────────────────
    Route::prefix('vendor')->middleware('vendor')->group(function () {
        Route::get('/dashboard', [VendorController::class, 'dashboard']);
        Route::get('/categories', [VendorController::class, 'categories']);
        Route::get('/brands', [VendorController::class, 'brands']);
        Route::get('/store', [VendorController::class, 'getStore']);
        Route::put('/store', [VendorController::class, 'updateStore']);
        Route::get('/products', [VendorController::class, 'myProducts']);
        Route::post('/products', [VendorController::class, 'createProduct']);
        Route::put('/products/{id}', [VendorController::class, 'updateProduct']);
        Route::delete('/products/{id}', [VendorController::class, 'deleteProduct']);
        Route::post('/products/{id}/toggle', [VendorController::class, 'toggleProduct']);
    });

    // ─── Admin-Only Routes ────────────────────────────────────
    Route::prefix('admin')->middleware('admin')->group(function () {
        // Admin's own profile
        Route::get('/me', [AdminProfileController::class, 'me']);
        Route::put('/profile', [AdminProfileController::class, 'update']);
        Route::post('/profile/photo', [AdminProfileController::class, 'uploadPhoto']);

        // Vendor management
        Route::get('/vendors', [VendorAdminController::class, 'index']);
        Route::post('/vendors', [VendorAdminController::class, 'storeVendor']);
        Route::delete('/vendors/{vendorId}/remove', [VendorAdminController::class, 'removeVendor']);
        Route::post('/vendors/{vendorId}/verify', [VendorAdminController::class, 'verifyStore']);

        // Marketplace catalogue management
        Route::get('/products', [ProductAdminController::class, 'index']);
        Route::post('/products', [ProductAdminController::class, 'store']);
        Route::put('/products/{id}', [ProductAdminController::class, 'update']);
        Route::delete('/products/{id}', [ProductAdminController::class, 'destroy']);
        Route::post('/products/{id}/feature', [ProductAdminController::class, 'toggleFeature']);
        Route::get('/categories', [ProductAdminController::class, 'categories']);
        Route::post('/categories', [ProductAdminController::class, 'createCategory']);
        Route::get('/brands', [ProductAdminController::class, 'brands']);
        Route::post('/brands', [ProductAdminController::class, 'createBrand']);
    });

    // Marketplace (Protected)
    Route::post('/marketplace/tag', [MarketplaceController::class, 'tagProduct']);
    Route::delete('/marketplace/tag', [MarketplaceController::class, 'removeTag']);

    // Wishlist
    Route::get('/wishlist', [WishlistController::class, 'index']);
    Route::post('/wishlist/{productId}', [WishlistController::class, 'store']);
    Route::delete('/wishlist/{productId}', [WishlistController::class, 'destroy']);
    Route::post('/wishlist/{productId}/toggle', [WishlistController::class, 'toggle']);

    // Reviews
    Route::post('/marketplace/products/{id}/reviews', [ReviewController::class, 'store']);
    Route::delete('/marketplace/reviews/{id}', [ReviewController::class, 'destroy']);

    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Profile
    Route::get('/profile', [ProfileController::class, 'me']);
    Route::put('/profile', [ProfileController::class, 'update']);
    Route::post('/profile/photo', [ProfileController::class, 'uploadPhoto']);
    Route::post('/users/{id}/follow', [ProfileController::class, 'follow']);
    Route::post('/users/{id}/unfollow', [ProfileController::class, 'unfollow']);
    Route::get('/my-following', [ProfileController::class, 'myFollowing']);


    // Catches
    Route::post('/catches', [CatchController::class, 'store']);
    Route::put('/catches/{id}', [CatchController::class, 'update']);
    Route::delete('/catches/{id}', [CatchController::class, 'destroy']);
    Route::post('/catches/{id}/like', [CatchController::class, 'like']);
    Route::post('/catches/{id}/comment', [CatchController::class, 'comment']);
    Route::delete('/comments/{id}', [CatchController::class, 'deleteComment']);

    // Feed
    Route::get('/feed/personal', [FeedController::class, 'personal']);
    Route::get('/feed/{id}', [FeedController::class, 'detail']);

    // Map
    Route::post('/map/spots', [MapController::class, 'addSpot']);
    Route::post('/map/spots/{id}/save', [MapController::class, 'saveSpot']);
    Route::delete('/map/spots/{id}/save', [MapController::class, 'unsaveSpot']);
    Route::get('/map/my-saved-spots', [MapController::class, 'mySavedSpots']);

    // Notifications
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::put('/notifications/{id}/read', [NotificationController::class, 'markRead']);
    Route::put('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);
    Route::post('/notifications/send', [NotificationController::class, 'send']);

    // Groups 
    Route::get('/groups/suggested/list', [GroupController::class, 'suggested']);
    Route::get('/anglers/suggested', [GroupController::class, 'suggestedAnglers']);
    Route::get('/my-groups', [GroupController::class, 'myGroups']);
    Route::post('/groups', [GroupController::class, 'store']);
    Route::delete('/groups/{id}', [GroupController::class, 'destroy']);
    Route::post('/groups/{id}/join', [GroupController::class, 'join']);
    Route::post('/groups/{id}/leave', [GroupController::class, 'leave']);
    Route::post('/groups/{id}/posts', [GroupController::class, 'createPost']);
});