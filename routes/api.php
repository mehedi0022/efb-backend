<?php

use App\Http\Controllers\Api\Admin\ApiIntegrationController;
use App\Http\Controllers\Api\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Api\Admin\BannerController;
use App\Http\Controllers\Api\Admin\BrandController;
use App\Http\Controllers\Api\Admin\CategoryController;
use App\Http\Controllers\Api\Admin\ColorController;
use App\Http\Controllers\Api\Admin\ContactController;
use App\Http\Controllers\Api\Admin\CreatePageController;
use App\Http\Controllers\Api\Admin\DashboardController;
use App\Http\Controllers\Api\Admin\EcomPixelController;
use App\Http\Controllers\Api\Admin\FraudCheckerController;
use App\Http\Controllers\Api\Admin\FooterSocialLinkController;
use App\Http\Controllers\Api\Admin\GeneralSettingController;
use App\Http\Controllers\Api\Admin\GoogleTagManagerController;
use App\Http\Controllers\Api\Admin\IpBlockController;
use App\Http\Controllers\Api\Admin\IncompleteOrderController;
use App\Http\Controllers\Api\Admin\OrderController;
use App\Http\Controllers\Api\Admin\OrderStatusController;
use App\Http\Controllers\Api\Admin\PermissionController;
use App\Http\Controllers\Api\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Api\Admin\RoleController;
use App\Http\Controllers\Api\Admin\ReportsController;
use App\Http\Controllers\Api\Admin\ReviewController;
use App\Http\Controllers\Api\Admin\ShippingChargeController as AdminShippingChargeController;
use App\Http\Controllers\Api\Admin\SizeController;
use App\Http\Controllers\Api\Admin\SubcategoryController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\IncompleteOrderController as PublicIncompleteOrderController;
use App\Http\Controllers\Api\BannerController as PublicBannerController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\ExternalProxyController;
use App\Http\Controllers\Api\PageController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\ShippingChargeController;
use App\Http\Controllers\Api\SiteDataController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('ip.block')->group(function () {
    // Public content endpoints
    Route::get('/settings', [SettingController::class, 'show']);
    Route::get('/site-data', [SiteDataController::class, 'index']);
    Route::get('/pages/{slug}', [PageController::class, 'show']);
    Route::get('/banners', [PublicBannerController::class, 'index']);
    Route::get('/home-data', [ProductController::class, 'home']);
    Route::get('/home-categories', [ProductController::class, 'homeCategories']);
    Route::get('/products/hot-deals', [ProductController::class, 'hotDeals']);
    Route::get('/products/latest', [ProductController::class, 'latest']);
    Route::get('/products/new-arrivals', [ProductController::class, 'newArrivals']);
    Route::get('/products/{slug}', [ProductController::class, 'details']);
    Route::get('/products', [ProductController::class, 'index']);

    // External API proxy endpoints
    Route::get('/external/featured-categories', [ExternalProxyController::class, 'featuredCategories']);
    Route::get('/external/menu-categories', [ExternalProxyController::class, 'menuCategories']);
    Route::get('/external/top-sell', [ExternalProxyController::class, 'topSell']);
    Route::get('/external/hot-deals', [ExternalProxyController::class, 'hotDeals']);
    Route::get('/external/category-products', [ExternalProxyController::class, 'categoryProducts']);
    Route::get('/external/category/{slug}', [ExternalProxyController::class, 'categoryProductsBySlug']);
    Route::get('/external/product/{slug}', [ExternalProxyController::class, 'productDetails']);
    Route::get('/external/search', [ExternalProxyController::class, 'searchProducts']);

    Route::get('/shipping-charges', [ShippingChargeController::class, 'index']);

    // Customer authentication
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/refresh-token', [AuthController::class, 'refresh']);

    Route::middleware('jwt:customer')->group(function () {
        Route::get('/user', [AuthController::class, 'user']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });

    // Cart + checkout: guest allowed, customer token optional
    Route::middleware('jwt.optional:customer')->group(function () {
        Route::post('/incomplete-orders/track', [PublicIncompleteOrderController::class, 'track']);
        Route::get('/cart', [CartController::class, 'getCart']);
        Route::post('/cart/add', [CartController::class, 'addToCart']);
        Route::post('/cart/external/add', [CartController::class, 'addExternal']);
        Route::put('/cart/items/{itemId}', [CartController::class, 'updateItem']);
        Route::delete('/cart/items/{itemId}', [CartController::class, 'removeItem']);
        Route::delete('/cart', [CartController::class, 'clearCart']);
        Route::post('/checkout', [CheckoutController::class, 'placeOrder']);
    });
});

Route::prefix('admin')->group(function () {
    Route::post('/login', [AdminAuthController::class, 'login']);
    Route::post('/refresh-token', [AdminAuthController::class, 'refresh']);

    Route::middleware('jwt:admin')->group(function () {
        Route::post('/logout', [AdminAuthController::class, 'logout']);
        Route::get('/me', [AdminAuthController::class, 'me']);
        Route::put('/profile', [AdminAuthController::class, 'updateProfile']);
        Route::put('/profile/password', [AdminAuthController::class, 'changePassword']);

        Route::get('/dashboard', [DashboardController::class, 'index'])->middleware('admin.permission:dashboard.view');

        Route::prefix('fraud-checker')->group(function () {
            Route::post('/check', [FraudCheckerController::class, 'check'])->middleware('admin.permission:fraud-checker.view');
        });

        Route::prefix('orders')->group(function () {
            Route::post('/', [OrderController::class, 'store'])->middleware('admin.permission:orders.edit');
            Route::get('/{status}', [OrderController::class, 'index'])->middleware('admin.permission:orders.view');
            Route::get('/detail/{id}', [OrderController::class, 'show'])->middleware('admin.permission:orders.view');
            Route::post('/update-status', [OrderController::class, 'updateStatus'])->middleware('admin.permission:orders.edit');
            Route::post('/assign-user', [OrderController::class, 'assignUser'])->middleware('admin.permission:orders.edit');
            Route::delete('/delete', [OrderController::class, 'destroy'])->middleware('admin.permission:orders.delete');
            Route::get('/statistics/all', [OrderController::class, 'statistics'])->middleware('admin.permission:orders.view');
            Route::post('/courier/steadfast', [OrderController::class, 'sendToSteadfast'])->middleware('admin.permission:orders.edit');
            Route::post('/send-dropshipping', [OrderController::class, 'sendToSteadfast'])->middleware('admin.permission:orders.edit');
            Route::post('/courier/pathao', [OrderController::class, 'sendToPathao'])->middleware('admin.permission:orders.edit');
            Route::post('/print', [OrderController::class, 'printOrders'])->middleware('admin.permission:orders.view');
            Route::get('/invoice/{invoiceId}', [OrderController::class, 'invoice'])->middleware('admin.permission:orders.view');
            Route::put('/invoice/{invoiceId}', [OrderController::class, 'updateByInvoice'])->middleware('admin.permission:orders.edit');
        });

        Route::prefix('products')->group(function () {
            Route::get('/', [AdminProductController::class, 'index'])->middleware('admin.permission:products.view');
            Route::get('/filters', [AdminProductController::class, 'filters'])->middleware('admin.permission:products.view');
            Route::get('/{id}', [AdminProductController::class, 'show'])->middleware('admin.permission:products.view');
            Route::post('/', [AdminProductController::class, 'store'])->middleware('admin.permission:products.create');
            Route::put('/{id}', [AdminProductController::class, 'update'])->middleware('admin.permission:products.edit');
            Route::post('/update-status', [AdminProductController::class, 'updateStatus'])->middleware('admin.permission:products.edit');
            Route::delete('/delete', [AdminProductController::class, 'destroy'])->middleware('admin.permission:products.delete');
        });

        Route::prefix('categories')->group(function () {
            Route::get('/', [CategoryController::class, 'index'])->middleware('admin.permission:categories.view');
            Route::get('/{id}', [CategoryController::class, 'show'])->middleware('admin.permission:categories.view');
            Route::post('/', [CategoryController::class, 'store'])->middleware('admin.permission:categories.create');
            Route::put('/{id}', [CategoryController::class, 'update'])->middleware('admin.permission:categories.edit');
            Route::post('/update-status', [CategoryController::class, 'updateStatus'])->middleware('admin.permission:categories.edit');
            Route::post('/toggle-show-home', [CategoryController::class, 'toggleShowHome'])->middleware('admin.permission:categories.edit');
            Route::delete('/delete', [CategoryController::class, 'destroy'])->middleware('admin.permission:categories.delete');
        });

        Route::prefix('subcategories')->group(function () {
            Route::get('/', [SubcategoryController::class, 'index'])->middleware('admin.permission:subcategories.view');
            Route::post('/', [SubcategoryController::class, 'store'])->middleware('admin.permission:subcategories.create');
            Route::put('/{id}', [SubcategoryController::class, 'update'])->middleware('admin.permission:subcategories.edit');
            Route::post('/update-status', [SubcategoryController::class, 'updateStatus'])->middleware('admin.permission:subcategories.edit');
            Route::delete('/delete', [SubcategoryController::class, 'destroy'])->middleware('admin.permission:subcategories.delete');
        });

        Route::prefix('brands')->group(function () {
            Route::get('/', [BrandController::class, 'index'])->middleware('admin.permission:brands.view');
            Route::post('/', [BrandController::class, 'store'])->middleware('admin.permission:brands.create');
            Route::put('/{id}', [BrandController::class, 'update'])->middleware('admin.permission:brands.edit');
            Route::post('/update-status', [BrandController::class, 'updateStatus'])->middleware('admin.permission:brands.edit');
            Route::delete('/delete', [BrandController::class, 'destroy'])->middleware('admin.permission:brands.delete');
        });

        Route::prefix('colors')->group(function () {
            Route::get('/', [ColorController::class, 'index'])->middleware('admin.permission:colors.view');
            Route::post('/', [ColorController::class, 'store'])->middleware('admin.permission:colors.create');
            Route::put('/{id}', [ColorController::class, 'update'])->middleware('admin.permission:colors.edit');
            Route::post('/update-status', [ColorController::class, 'updateStatus'])->middleware('admin.permission:colors.edit');
            Route::delete('/delete', [ColorController::class, 'destroy'])->middleware('admin.permission:colors.delete');
        });

        Route::prefix('sizes')->group(function () {
            Route::get('/', [SizeController::class, 'index'])->middleware('admin.permission:sizes.view');
            Route::post('/', [SizeController::class, 'store'])->middleware('admin.permission:sizes.create');
            Route::put('/{id}', [SizeController::class, 'update'])->middleware('admin.permission:sizes.edit');
            Route::post('/update-status', [SizeController::class, 'updateStatus'])->middleware('admin.permission:sizes.edit');
            Route::delete('/delete', [SizeController::class, 'destroy'])->middleware('admin.permission:sizes.delete');
        });

        Route::prefix('reviews')->group(function () {
            Route::get('/', [ReviewController::class, 'index'])->middleware('admin.permission:reviews.view');
            Route::get('/pending', [ReviewController::class, 'pending'])->middleware('admin.permission:reviews.view');
            Route::get('/meta', [ReviewController::class, 'meta'])->middleware('admin.permission:reviews.view');
            Route::post('/', [ReviewController::class, 'store'])->middleware('admin.permission:reviews.create');
            Route::put('/{id}', [ReviewController::class, 'update'])->middleware('admin.permission:reviews.edit');
            Route::post('/{id}/activate', [ReviewController::class, 'activate'])->middleware('admin.permission:reviews.edit');
            Route::post('/{id}/deactivate', [ReviewController::class, 'deactivate'])->middleware('admin.permission:reviews.edit');
            Route::delete('/{id}', [ReviewController::class, 'destroy'])->middleware('admin.permission:reviews.delete');
        });

        Route::prefix('settings')->group(function () {
            Route::get('/', [GeneralSettingController::class, 'index'])->middleware('admin.permission:settings.view');
            Route::post('/', [GeneralSettingController::class, 'store'])->middleware('admin.permission:settings.create');
            Route::put('/{id}', [GeneralSettingController::class, 'update'])->middleware('admin.permission:settings.edit');
            Route::post('/update-status', [GeneralSettingController::class, 'updateStatus'])->middleware('admin.permission:settings.edit');
            Route::delete('/delete', [GeneralSettingController::class, 'destroy'])->middleware('admin.permission:settings.delete');
        });

        Route::prefix('ip-blocks')->group(function () {
            Route::get('/', [IpBlockController::class, 'index'])->middleware('admin.permission:ip-blocking.view');
            Route::post('/', [IpBlockController::class, 'store'])->middleware('admin.permission:ip-blocking.create');
            Route::put('/{id}', [IpBlockController::class, 'update'])->middleware('admin.permission:ip-blocking.edit');
            Route::delete('/delete', [IpBlockController::class, 'destroy'])->middleware('admin.permission:ip-blocking.delete');
        });

        Route::prefix('contacts')->group(function () {
            Route::get('/', [ContactController::class, 'index'])->middleware('admin.permission:settings.view');
            Route::post('/', [ContactController::class, 'store'])->middleware('admin.permission:settings.create');
            Route::put('/{id}', [ContactController::class, 'update'])->middleware('admin.permission:settings.edit');
            Route::post('/update-status', [ContactController::class, 'updateStatus'])->middleware('admin.permission:settings.edit');
            Route::delete('/delete', [ContactController::class, 'destroy'])->middleware('admin.permission:settings.delete');
        });

        Route::prefix('pages')->group(function () {
            Route::get('/', [CreatePageController::class, 'index'])->middleware('admin.permission:settings.view');
            Route::post('/', [CreatePageController::class, 'store'])->middleware('admin.permission:settings.create');
            Route::put('/{id}', [CreatePageController::class, 'update'])->middleware('admin.permission:settings.edit');
            Route::post('/update-status', [CreatePageController::class, 'updateStatus'])->middleware('admin.permission:settings.edit');
            Route::delete('/delete', [CreatePageController::class, 'destroy'])->middleware('admin.permission:settings.delete');
        });

        Route::prefix('footer-social-links')->group(function () {
            Route::get('/', [FooterSocialLinkController::class, 'index'])->middleware('admin.permission:settings.view');
            Route::post('/', [FooterSocialLinkController::class, 'store'])->middleware('admin.permission:settings.create');
            Route::put('/{id}', [FooterSocialLinkController::class, 'update'])->middleware('admin.permission:settings.edit');
            Route::post('/update-status', [FooterSocialLinkController::class, 'updateStatus'])->middleware('admin.permission:settings.edit');
            Route::delete('/delete', [FooterSocialLinkController::class, 'destroy'])->middleware('admin.permission:settings.delete');
        });

        Route::prefix('shipping-charges')->group(function () {
            Route::get('/', [AdminShippingChargeController::class, 'index'])->middleware('admin.permission:settings.view');
            Route::post('/', [AdminShippingChargeController::class, 'store'])->middleware('admin.permission:settings.create');
            Route::put('/{id}', [AdminShippingChargeController::class, 'update'])->middleware('admin.permission:settings.edit');
            Route::post('/update-status', [AdminShippingChargeController::class, 'updateStatus'])->middleware('admin.permission:settings.edit');
            Route::delete('/delete', [AdminShippingChargeController::class, 'destroy'])->middleware('admin.permission:settings.delete');
        });

        Route::prefix('order-statuses')->group(function () {
            Route::get('/', [OrderStatusController::class, 'index'])->middleware('admin.permission:settings.view');
            Route::post('/', [OrderStatusController::class, 'store'])->middleware('admin.permission:settings.create');
            Route::put('/{id}', [OrderStatusController::class, 'update'])->middleware('admin.permission:settings.edit');
            Route::post('/update-status', [OrderStatusController::class, 'updateStatus'])->middleware('admin.permission:settings.edit');
            Route::delete('/delete', [OrderStatusController::class, 'destroy'])->middleware('admin.permission:settings.delete');
        });

        Route::prefix('integrations')->group(function () {
            Route::get('/payment', [ApiIntegrationController::class, 'paymentIndex'])->middleware('admin.permission:integrations.view');
            Route::put('/payment/{id}', [ApiIntegrationController::class, 'paymentUpdate'])->middleware('admin.permission:integrations.edit');
            Route::get('/sms', [ApiIntegrationController::class, 'smsIndex'])->middleware('admin.permission:integrations.view');
            Route::put('/sms/{id}', [ApiIntegrationController::class, 'smsUpdate'])->middleware('admin.permission:integrations.edit');
            Route::get('/courier', [ApiIntegrationController::class, 'courierIndex'])->middleware('admin.permission:integrations.view');
            Route::put('/courier/{id}', [ApiIntegrationController::class, 'courierUpdate'])->middleware('admin.permission:integrations.edit');
            Route::post('/pathao-token', [ApiIntegrationController::class, 'getPathaoToken'])->middleware('admin.permission:integrations.edit');
        });

        Route::prefix('pixels')->group(function () {
            Route::get('/', [EcomPixelController::class, 'index'])->middleware('admin.permission:pixels.view');
            Route::post('/', [EcomPixelController::class, 'store'])->middleware('admin.permission:pixels.create');
            Route::put('/{id}', [EcomPixelController::class, 'update'])->middleware('admin.permission:pixels.edit');
            Route::post('/update-status', [EcomPixelController::class, 'updateStatus'])->middleware('admin.permission:pixels.edit');
            Route::delete('/delete', [EcomPixelController::class, 'destroy'])->middleware('admin.permission:pixels.delete');
        });

        Route::prefix('tag-managers')->group(function () {
            Route::get('/', [GoogleTagManagerController::class, 'index'])->middleware('admin.permission:tag-managers.view');
            Route::post('/', [GoogleTagManagerController::class, 'store'])->middleware('admin.permission:tag-managers.create');
            Route::put('/{id}', [GoogleTagManagerController::class, 'update'])->middleware('admin.permission:tag-managers.edit');
            Route::post('/update-status', [GoogleTagManagerController::class, 'updateStatus'])->middleware('admin.permission:tag-managers.edit');
            Route::delete('/delete', [GoogleTagManagerController::class, 'destroy'])->middleware('admin.permission:tag-managers.delete');
        });

        Route::prefix('banners')->group(function () {
            Route::get('/', [BannerController::class, 'index'])->middleware('admin.permission:banners.view');
            Route::get('/meta', [BannerController::class, 'meta'])->middleware('admin.permission:banners.view');
            Route::post('/', [BannerController::class, 'store'])->middleware('admin.permission:banners.create');
            Route::post('/update-status', [BannerController::class, 'updateStatus'])->middleware('admin.permission:banners.edit');
            Route::post('/{id}', [BannerController::class, 'update'])->middleware('admin.permission:banners.edit');
            Route::delete('/delete', [BannerController::class, 'destroy'])->middleware('admin.permission:banners.delete');
        });

        Route::prefix('incomplete-orders')->group(function () {
            Route::get('/', [IncompleteOrderController::class, 'index'])->middleware('admin.permission:incomplete-orders.view');
            Route::get('/meta', [IncompleteOrderController::class, 'meta'])->middleware('admin.permission:incomplete-orders.view');
            Route::get('/{id}', [IncompleteOrderController::class, 'show'])->middleware('admin.permission:incomplete-orders.view');
            Route::post('/', [IncompleteOrderController::class, 'store'])->middleware('admin.permission:incomplete-orders.create');
            Route::put('/{id}', [IncompleteOrderController::class, 'update'])->middleware('admin.permission:incomplete-orders.edit');
            Route::delete('/{id}', [IncompleteOrderController::class, 'destroy'])->middleware('admin.permission:incomplete-orders.delete');
            Route::post('/update-qty', [IncompleteOrderController::class, 'updateQty'])->middleware('admin.permission:incomplete-orders.edit');
            Route::post('/update-shipping', [IncompleteOrderController::class, 'updateShipping'])->middleware('admin.permission:incomplete-orders.edit');
            Route::post('/{id}/create-order', [IncompleteOrderController::class, 'createOrder'])->middleware('admin.permission:incomplete-orders.create');
        });

        Route::prefix('reports')->group(function () {
            Route::get('/orders', [ReportsController::class, 'orderReport'])->middleware('admin.permission:reports.view');
        });

        Route::prefix('users')->group(function () {
            Route::get('/', [UserController::class, 'index'])->middleware('admin.permission:users.view');
            Route::get('/{id}', [UserController::class, 'show'])->middleware('admin.permission:users.view');
            Route::post('/', [UserController::class, 'store'])->middleware('admin.permission:users.create');
            Route::put('/{id}', [UserController::class, 'update'])->middleware('admin.permission:users.edit');
            Route::delete('/{id}', [UserController::class, 'destroy'])->middleware('admin.permission:users.delete');
        });

        Route::prefix('roles')->group(function () {
            Route::get('/', [RoleController::class, 'index'])->middleware('admin.permission:roles.view');
            Route::get('/{id}', [RoleController::class, 'show'])->middleware('admin.permission:roles.view');
            Route::post('/', [RoleController::class, 'store'])->middleware('admin.permission:roles.create');
            Route::put('/{id}', [RoleController::class, 'update'])->middleware('admin.permission:roles.edit');
            Route::delete('/{id}', [RoleController::class, 'destroy'])->middleware('admin.permission:roles.delete');
        });

        Route::prefix('permissions')->group(function () {
            Route::get('/', [PermissionController::class, 'index'])->middleware('admin.permission:permissions.view');
            Route::get('/{id}', [PermissionController::class, 'show'])->middleware('admin.permission:permissions.view');
            Route::post('/', [PermissionController::class, 'store'])->middleware('admin.permission:permissions.create');
            Route::put('/{id}', [PermissionController::class, 'update'])->middleware('admin.permission:permissions.edit');
            Route::delete('/{id}', [PermissionController::class, 'destroy'])->middleware('admin.permission:permissions.delete');
        });
    });
});
