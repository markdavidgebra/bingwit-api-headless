<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\CatchController;
use App\Http\Controllers\Api\FeedController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\ForecastController;
use App\Http\Controllers\Api\MapController;
use App\Http\Controllers\Api\LeaderboardController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\MarketplaceController;
use App\Http\Controllers\Api\MarketplaceCheckoutController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\Admin\DeliveryAdminController;
use App\Http\Controllers\Api\WishlistController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\Admin\ProductAdminController;
use App\Http\Controllers\Api\Vendor\VendorController;
use App\Http\Controllers\Api\Admin\VendorAdminController;
use App\Http\Controllers\Api\Admin\AdminProfileController;
use App\Http\Controllers\Api\TournamentController;
use App\Http\Controllers\Api\FishingBoatController;
use App\Http\Controllers\Api\Admin\TournamentAdminController;
use App\Http\Controllers\Api\Admin\FishingBoatAdminController;
use App\Http\Controllers\Api\Admin\CatchAdminController;
use App\Http\Controllers\Api\Admin\EconomyAdminController;
use App\Http\Controllers\Api\Admin\UserAdminController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\CatchEconomyController;
use App\Http\Controllers\Api\GearDonationController;
use App\Http\Controllers\Api\MerchantGiftController;
use App\Http\Controllers\Api\RewardController;
use App\Http\Controllers\Api\ConnectController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\ModerationController;
use App\Http\Controllers\Api\ResortController;
use App\Http\Controllers\Api\Admin\ConnectAdminController;
use App\Http\Controllers\Api\Admin\ReportAdminController;
use App\Http\Controllers\Api\AccountDeletionRequestController;
use App\Http\Controllers\Api\Admin\AccountDeletionAdminController;
// ─── Public Routes ────────────────────────────────────────

// ─── Diagnostics (Public, read-only) ──────────────────────
Route::get('/_health/uploads', [HealthController::class, 'uploads']);

// ─── Marketplace (Public) ─────────────────────────────────
Route::get('/marketplace', [MarketplaceController::class, 'home']);
Route::get('/marketplace/products', [MarketplaceController::class, 'products']);
Route::get('/marketplace/products/{id}', [MarketplaceController::class, 'show']);
Route::get('/marketplace/search', [MarketplaceController::class, 'search']);
Route::get('/marketplace/categories', [MarketplaceController::class, 'categories']);
Route::get('/marketplace/categories/{id}', [MarketplaceController::class, 'byCategory']);
Route::get('/marketplace/products/{id}/reviews', [ReviewController::class, 'index']);
Route::get('/marketplace/checkout/return', [MarketplaceCheckoutController::class, 'returnPage']);
Route::post('/marketplace/checkout/webhook', [MarketplaceCheckoutController::class, 'webhook']);

Route::get('/rewards', [RewardController::class, 'index']);
Route::get('/merchant-gifts/catalog', [MerchantGiftController::class, 'catalog']);
Route::get('/wallet/settings', [WalletController::class, 'settings']);

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/vendor/login', [AuthController::class, 'loginVendor']);
Route::post('/admin/login', [AuthController::class, 'loginAdmin']);

Route::post('/account-deletion-requests', [AccountDeletionRequestController::class, 'store'])
    ->middleware('throttle:5,1');

// Forecast
Route::get('/forecast', [ForecastController::class, 'getForecast']);

// Feed
Route::get('/feed', [FeedController::class, 'global']);
Route::get('/feed/search', [FeedController::class, 'search']);
// Cross-posted tournament announcements that should appear above the feed.
Route::get('/feed/announcements', [TournamentController::class, 'announcements']);

// ─── Tournaments (Public) ─────────────────────────────────
Route::get('/tournaments', [TournamentController::class, 'index']);
Route::get('/tournaments/checkout/return', [TournamentController::class, 'checkoutReturn']);
Route::get('/tournaments/{id}/days/{dayId}/leaderboard', [TournamentController::class, 'dayLeaderboard']);
Route::get('/tournaments/{id}/days', [TournamentController::class, 'days']);
Route::get('/tournaments/{id}/posts', [TournamentController::class, 'posts']);
Route::get('/tournaments/{id}', [TournamentController::class, 'show']);

// ─── Fishing Boats (Public) ───────────────────────────────
Route::get('/fishing-boats', [FishingBoatController::class, 'index']);
Route::get('/fishing-boats/{id}', [FishingBoatController::class, 'show']);

// Map
Route::get('/map/spots', [MapController::class, 'spots']);
Route::get('/map/nearby-catches', [MapController::class, 'nearbyCatches']);
Route::get('/map/nearby-anglers', [MapController::class, 'nearbyAnglers']);

// Connect / resorts (public browse)
Route::get('/connect/search', [ConnectController::class, 'search']);
Route::get('/anglers/search', [ProfileController::class, 'searchAnglers']);
Route::get('/resorts', [ResortController::class, 'index']);
Route::get('/resorts/{id}', [ResortController::class, 'show']);

// Leaderboard
Route::get('/leaderboard/biggest', [LeaderboardController::class, 'biggestCatch']);
Route::get('/leaderboard/most', [LeaderboardController::class, 'mostCatches']);
Route::get('/leaderboard/all-time-biggest', [LeaderboardController::class, 'allTimeBiggest']);
Route::get('/leaderboard/all-time-most', [LeaderboardController::class, 'allTimeMost']);
Route::get('/leaderboard/tournament-active', [LeaderboardController::class, 'activeTournamentBoard']);
Route::get('/leaderboard/tournament-days/{dayId}', [LeaderboardController::class, 'tournamentDay']);

// Profiles & Catches
Route::get('/users/{id}', [ProfileController::class, 'show']);
Route::get('/users/{id}/catches', [CatchController::class, 'userCatches']);
Route::get('/catches/{id}', [CatchController::class, 'show']);

// ─── Protected Routes ─────────────────────────────────────

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/users', [ProfileController::class, 'allUsers']);

    // ─── Vendor Routes (Vendor or Admin) ──────────────────────
    Route::prefix('vendor')->middleware('vendor')->group(function () {
        Route::get('/dashboard', [VendorController::class, 'dashboard']);
        Route::get('/gifts', [VendorController::class, 'gifts']);
        Route::get('/orders', [VendorController::class, 'orders']);
        Route::post('/orders/{id}/shipping', [VendorController::class, 'updateOrderShipping']);
        Route::get('/anglers', [VendorController::class, 'searchAnglers']);
        Route::post('/stars/grant', [VendorController::class, 'grantStars']);
        Route::get('/categories', [VendorController::class, 'categories']);
        Route::get('/brands', [VendorController::class, 'brands']);
        Route::get('/store', [VendorController::class, 'getStore']);
        Route::put('/store', [VendorController::class, 'updateStore']);
        Route::post('/store/logo', [VendorController::class, 'uploadLogo']);
        Route::delete('/store/logo', [VendorController::class, 'removeLogo']);
        Route::get('/products', [VendorController::class, 'myProducts']);
        Route::post('/products', [VendorController::class, 'createProduct']);
        Route::put('/products/{id}', [VendorController::class, 'updateProduct']);
        Route::delete('/products/{id}', [VendorController::class, 'deleteProduct']);
        Route::post('/products/{id}/toggle', [VendorController::class, 'toggleProduct']);
        Route::post('/products/{id}/images', [VendorController::class, 'addProductImages']);
        Route::delete('/products/{id}/images/{imageId}', [VendorController::class, 'deleteProductImage']);
        Route::post('/products/{id}/images/{imageId}/primary', [VendorController::class, 'setPrimaryProductImage']);
    });

    // ─── Admin-Only Routes ────────────────────────────────────
    Route::prefix('admin')->middleware('admin')->group(function () {
        // Admin's own profile
        Route::get('/me', [AdminProfileController::class, 'me']);
        Route::put('/profile', [AdminProfileController::class, 'update']);
        Route::post('/profile/photo', [AdminProfileController::class, 'uploadPhoto']);

        // Vendor management
        Route::get('/users', [VendorAdminController::class, 'eligibleUsers']);
        Route::get('/vendors', [VendorAdminController::class, 'index']);
        Route::post('/vendors', [VendorAdminController::class, 'storeVendor']);
        Route::post('/vendors/assign', [VendorAdminController::class, 'assignVendor']);
        Route::delete('/vendors/{vendorId}/remove', [VendorAdminController::class, 'removeVendor']);
        Route::post('/vendors/{vendorId}/verify', [VendorAdminController::class, 'verifyStore']);
        Route::get('/delivery', [DeliveryAdminController::class, 'show']);
        Route::put('/delivery/defaults', [DeliveryAdminController::class, 'updateDefaults']);
        Route::put('/vendors/{vendorId}/delivery', [DeliveryAdminController::class, 'updateVendor']);

        // Marketplace catalogue management
        Route::get('/products', [ProductAdminController::class, 'index']);
        Route::post('/products', [ProductAdminController::class, 'store']);
        Route::put('/products/{id}', [ProductAdminController::class, 'update']);
        Route::delete('/products/{id}', [ProductAdminController::class, 'destroy']);
        Route::post('/products/{id}/feature', [ProductAdminController::class, 'toggleFeature']);
        Route::get('/categories', [ProductAdminController::class, 'categories']);
        Route::post('/categories', [ProductAdminController::class, 'createCategory']);
        Route::put('/categories/{id}', [ProductAdminController::class, 'updateCategory']);
        Route::delete('/categories/{id}', [ProductAdminController::class, 'destroyCategory']);
        Route::get('/brands', [ProductAdminController::class, 'brands']);
        Route::post('/brands', [ProductAdminController::class, 'createBrand']);

        // Catch moderation
        Route::delete('/catches/{id}', [CatchAdminController::class, 'destroy']);

        // UGC reports (Play Store moderation)
        Route::get('/reports', [ReportAdminController::class, 'index']);
        Route::put('/reports/{id}', [ReportAdminController::class, 'update']);

        // Account deletion requests (Play Store Data safety)
        Route::get('/account-deletion-requests', [AccountDeletionAdminController::class, 'index']);
        Route::get('/account-deletion-requests/{id}', [AccountDeletionAdminController::class, 'show']);
        Route::post('/account-deletion-requests/{id}/process', [AccountDeletionAdminController::class, 'process']);
        Route::post('/account-deletion-requests/{id}/reject', [AccountDeletionAdminController::class, 'reject']);

        // ─── User management ──────────────────────────────────
        Route::get('/users', [UserAdminController::class, 'index']);
        Route::get('/users/{id}', [UserAdminController::class, 'show']);
        Route::get('/users/{id}/tournaments', [UserAdminController::class, 'tournaments']);
        Route::post('/users/{id}/tournaments', [UserAdminController::class, 'joinTournament']);
        Route::delete('/users/{id}/tournaments/{tournamentId}', [UserAdminController::class, 'leaveTournament']);

        // ─── Tournament management ────────────────────────────
        Route::get('/tournaments', [TournamentAdminController::class, 'index']);
        Route::post('/tournaments', [TournamentAdminController::class, 'store']);
        Route::get('/tournaments/{id}', [TournamentAdminController::class, 'show']);
        Route::put('/tournaments/{id}', [TournamentAdminController::class, 'update']);
        Route::post('/tournaments/{id}/cover', [TournamentAdminController::class, 'uploadCover']);
        Route::delete('/tournaments/{id}', [TournamentAdminController::class, 'destroy']);
        Route::get('/tournaments/{id}/posts', [TournamentAdminController::class, 'posts']);
        Route::post('/tournaments/{id}/posts', [TournamentAdminController::class, 'createPost']);
        Route::get('/tournaments/{id}/participants', [TournamentAdminController::class, 'participants']);
        Route::post('/tournaments/{id}/participants', [TournamentAdminController::class, 'addParticipant']);
        Route::delete('/tournaments/{id}/participants/{participantId}', [TournamentAdminController::class, 'removeParticipant']);
        Route::get('/users/search', [TournamentAdminController::class, 'searchUsers']);
        Route::get('/tournaments/{id}/days', [TournamentAdminController::class, 'days']);
        Route::get('/tournaments/{id}/days/{dayId}/participants', [TournamentAdminController::class, 'dayParticipants']);
        Route::put('/tournaments/{id}/days/{dayId}/participants', [TournamentAdminController::class, 'syncDayParticipants']);
        Route::get('/tournaments/{id}/days/{dayId}/leaderboard', [TournamentAdminController::class, 'dayLeaderboard']);
        Route::put('/tournament-posts/{postId}', [TournamentAdminController::class, 'updatePost']);
        Route::delete('/tournament-posts/{postId}', [TournamentAdminController::class, 'destroyPost']);

        // ─── Fishing boat management ──────────────────────────
        Route::get('/fishing-boats', [FishingBoatAdminController::class, 'index']);
        Route::post('/fishing-boats', [FishingBoatAdminController::class, 'store']);
        Route::get('/fishing-boats/{id}', [FishingBoatAdminController::class, 'show']);
        Route::put('/fishing-boats/{id}', [FishingBoatAdminController::class, 'update']);
        Route::post('/fishing-boats/{id}/cover', [FishingBoatAdminController::class, 'uploadCover']);
        Route::delete('/fishing-boats/{id}', [FishingBoatAdminController::class, 'destroy']);
        Route::get('/fishing-boats/{id}/bookings', [FishingBoatAdminController::class, 'bookings']);
        Route::put('/boat-bookings/{bookingId}/status', [FishingBoatAdminController::class, 'updateBookingStatus']);

        // Connect — municipalities & resorts (localized management)
        Route::get('/municipalities', [ConnectAdminController::class, 'municipalities']);
        Route::post('/municipalities', [ConnectAdminController::class, 'storeMunicipality']);
        Route::put('/municipalities/{id}', [ConnectAdminController::class, 'updateMunicipality']);
        Route::get('/resorts', [ConnectAdminController::class, 'resorts']);
        Route::post('/resorts', [ConnectAdminController::class, 'storeResort']);
        Route::put('/resorts/{id}', [ConnectAdminController::class, 'updateResort']);

        // Economy management
        Route::get('/economy/overview', [EconomyAdminController::class, 'overview']);
        Route::get('/economy/wallets', [EconomyAdminController::class, 'wallets']);
        Route::get('/economy/referrals', [EconomyAdminController::class, 'referrals']);
        Route::post('/economy/wallets/{userId}/adjust', [EconomyAdminController::class, 'adjustWallet']);
        Route::get('/economy/transactions', [EconomyAdminController::class, 'transactions']);
        Route::get('/economy/catch-gifts', [EconomyAdminController::class, 'catchGifts']);
        Route::get('/economy/gear-donations', [EconomyAdminController::class, 'gearDonations']);
        Route::get('/economy/merchant-gifts', [EconomyAdminController::class, 'merchantGifts']);
        Route::get('/economy/settings', [EconomyAdminController::class, 'settings']);
        Route::put('/economy/settings', [EconomyAdminController::class, 'updateSettings']);
        Route::get('/economy/rewards', [EconomyAdminController::class, 'rewards']);
        Route::post('/economy/rewards', [EconomyAdminController::class, 'storeReward']);
        Route::put('/economy/rewards/{id}', [EconomyAdminController::class, 'updateReward']);
        Route::delete('/economy/rewards/{id}', [EconomyAdminController::class, 'deleteReward']);
        Route::get('/economy/gift-catalog', [EconomyAdminController::class, 'giftCatalog']);
        Route::post('/economy/gift-catalog', [EconomyAdminController::class, 'storeGiftCatalog']);
        Route::put('/economy/gift-catalog/{id}', [EconomyAdminController::class, 'updateGiftCatalog']);
        Route::delete('/economy/gift-catalog/{id}', [EconomyAdminController::class, 'deleteGiftCatalog']);
        Route::get('/economy/redemptions', [EconomyAdminController::class, 'redemptions']);
        Route::post('/economy/redemptions/{id}/fulfill', [EconomyAdminController::class, 'fulfillRedemption']);
        Route::get('/economy/product-claims', [EconomyAdminController::class, 'productClaims']);
        Route::post('/economy/product-claims/{id}/fulfill', [EconomyAdminController::class, 'fulfillProductClaim']);
        Route::get('/economy/product-orders', [EconomyAdminController::class, 'productOrders']);
        Route::post('/economy/product-orders/{id}/fulfill', [EconomyAdminController::class, 'fulfillProductOrder']);
        Route::post('/economy/product-orders/{id}/shipping', [EconomyAdminController::class, 'updateProductOrderShipping']);
    });

    // Marketplace (Protected)
    Route::post('/marketplace/tag', [MarketplaceController::class, 'tagProduct']);
    Route::delete('/marketplace/tag', [MarketplaceController::class, 'removeTag']);
    Route::post('/marketplace/products/{id}/claim', [MarketplaceController::class, 'claim']);
    Route::get('/marketplace/claims/mine', [MarketplaceController::class, 'myClaims']);
    Route::get('/marketplace/products/{id}/fulfillment', [MarketplaceCheckoutController::class, 'options']);
    Route::post('/marketplace/products/{id}/quote', [MarketplaceCheckoutController::class, 'quote']);
    Route::post('/marketplace/products/{id}/checkout', [MarketplaceCheckoutController::class, 'checkout']);
    Route::get('/marketplace/cart', [CartController::class, 'index']);
    Route::post('/marketplace/cart', [CartController::class, 'store']);
    Route::patch('/marketplace/cart/{productId}', [CartController::class, 'update']);
    Route::delete('/marketplace/cart/{productId}', [CartController::class, 'destroy']);
    Route::get('/marketplace/cart/fulfillment', [CartController::class, 'options']);
    Route::post('/marketplace/cart/quote', [CartController::class, 'quote']);
    Route::post('/marketplace/cart/checkout', [CartController::class, 'checkout']);
    Route::get('/marketplace/orders/mine', [MarketplaceCheckoutController::class, 'mine']);
    Route::get('/marketplace/orders/{id}', [MarketplaceCheckoutController::class, 'show']);
    Route::post('/marketplace/orders/{id}/sync', [MarketplaceCheckoutController::class, 'sync']);

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

    // UGC safety — report & block (required for Play Store social/chat apps)
    Route::post('/reports', [ModerationController::class, 'report']);
    Route::get('/blocked-users', [ModerationController::class, 'blockedUsers']);
    Route::get('/users/{id}/block-status', [ModerationController::class, 'blockStatus']);
    Route::post('/users/{id}/block', [ModerationController::class, 'block']);
    Route::delete('/users/{id}/block', [ModerationController::class, 'unblock']);
    Route::get('/anglers/suggested', [ProfileController::class, 'suggestedAnglers']);

    // Chat with anglers
    Route::get('/chat/inbox', [ChatController::class, 'inbox']);
    Route::get('/chat/{userId}', [ChatController::class, 'thread']);
    Route::post('/chat/{userId}', [ChatController::class, 'send']);

    // Resort reviews
    Route::post('/resorts/{id}/reviews', [ResortController::class, 'review']);

    // Tournaments (auth users register/withdraw)
    Route::get('/tournaments/my-active-days', [TournamentController::class, 'myActiveDays']);
    Route::post('/tournaments/{id}/register', [TournamentController::class, 'register']);
    Route::post('/tournaments/{id}/register/sync', [TournamentController::class, 'syncRegister']);
    Route::delete('/tournaments/{id}/register', [TournamentController::class, 'unregister']);

    // Fishing boat bookings
    Route::get('/boat-bookings', [FishingBoatController::class, 'myBookings']);
    Route::post('/fishing-boats/{id}/book', [FishingBoatController::class, 'book']);
    Route::delete('/boat-bookings/{id}', [FishingBoatController::class, 'cancelBooking']);

    // Catches
    Route::post('/catches', [CatchController::class, 'store']);
    Route::put('/catches/{id}', [CatchController::class, 'update']);
    Route::delete('/catches/{id}', [CatchController::class, 'destroy']);
    Route::post('/catches/{id}/like', [CatchController::class, 'like']);
    Route::post('/catches/{id}/love', [CatchController::class, 'love']);
    Route::post('/catches/{id}/comment', [CatchController::class, 'comment']);
    Route::delete('/comments/{id}', [CatchController::class, 'deleteComment']);

    // Wallet & economy
    Route::get('/wallet', [WalletController::class, 'show']);
    Route::post('/wallet/convert', [WalletController::class, 'convert']);
    Route::post('/catches/{id}/fish-points', [CatchEconomyController::class, 'giftFishPoints']);
    Route::post('/catches/{id}/stars', [CatchEconomyController::class, 'giftStars']);
    Route::post('/catches/{id}/confirm-lesson', [CatchEconomyController::class, 'confirmLesson']);
    Route::delete('/catches/{id}/confirm-lesson', [CatchEconomyController::class, 'unconfirmLesson']);
    Route::post('/donations', [GearDonationController::class, 'store']);
    Route::get('/donations/mine', [GearDonationController::class, 'myDonations']);
    Route::put('/donations/{id}/status', [GearDonationController::class, 'updateStatus']);
    Route::post('/vendors/{id}/gifts', [MerchantGiftController::class, 'send']);
    Route::post('/rewards/{id}/redeem', [RewardController::class, 'redeem']);
    Route::get('/redemptions/mine', [RewardController::class, 'myRedemptions']);

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

});