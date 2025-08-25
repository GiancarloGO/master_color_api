<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\ClientAuthController;
use App\Http\Controllers\ClientAddressController;
use App\Http\Controllers\ClientOrderController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ClientCartController;
use App\Http\Controllers\DocumentLookupController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\StockMovementController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| AUTH ROUTES
|--------------------------------------------------------------------------
*/

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->name('auth.register');
    Route::post('/login', [AuthController::class, 'login'])->name('auth.login');

    // Rutas que requieren autenticación
    Route::middleware(['jwt.auth', 'check.token.version'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('/refresh', [AuthController::class, 'refresh'])->name('auth.refresh');
        Route::post('/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('/change-password', [AuthController::class, 'changePassword'])->name('auth.change-password');
    });
});

/*
|--------------------------------------------------------------------------
| CLIENT AUTH ROUTES
|--------------------------------------------------------------------------
*/

Route::prefix('client/auth')->group(function () {
    Route::post('/register', [ClientAuthController::class, 'register'])->name('client.auth.register');
    Route::post('/login', [ClientAuthController::class, 'login'])->name('client.auth.login');
    Route::post('/verify-email', [ClientAuthController::class, 'verifyEmail'])->name('client.auth.verify-email');
    Route::post('/resend-verification', [ClientAuthController::class, 'resendVerificationEmail'])->name('client.auth.resend-verification');
    Route::post('/forgot-password', [ClientAuthController::class, 'forgotPassword'])->name('client.auth.forgot-password');
    Route::post('/reset-password', [ClientAuthController::class, 'resetPassword'])->name('client.auth.reset-password');

    // Rutas que requieren autenticación de cliente
    Route::middleware([\App\Http\Middleware\ClientAuth::class])->group(function () {
        Route::get('/profile', [ClientAuthController::class, 'profile'])->name('client.profile');
        Route::post('/me', [ClientAuthController::class, 'me'])->name('client.me');
        Route::put('/profile', [ClientAuthController::class, 'updateProfile'])->name('client.profile.update');
        Route::post('/change-password', [ClientAuthController::class, 'changePassword'])->name('client.password.change');
        Route::post('/refresh', [ClientAuthController::class, 'refresh'])->name('client.auth.refresh');
        Route::post('/logout', [ClientAuthController::class, 'logout'])->name('client.auth.logout');
    });
});

/*
|--------------------------------------------------------------------------
| CLIENT ADDRESS ROUTES
|--------------------------------------------------------------------------
*/

Route::prefix('client/addresses')->middleware([\App\Http\Middleware\ClientAuth::class])->group(function () {
    Route::get('/', [ClientAddressController::class, 'index'])->name('client.addresses.index');
    Route::post('/', [ClientAddressController::class, 'store'])->name('client.addresses.store');
    Route::get('/{id}', [ClientAddressController::class, 'show'])->name('client.addresses.show');
    Route::put('/{id}', [ClientAddressController::class, 'update'])->name('client.addresses.update');
    Route::delete('/{id}', [ClientAddressController::class, 'destroy'])->name('client.addresses.destroy');
    Route::put('/{id}/set-main', [ClientAddressController::class, 'setAsMain'])->name('client.addresses.set-main');
});

/*
|--------------------------------------------------------------------------
| CLIENT ORDER ROUTES
|--------------------------------------------------------------------------
*/

Route::prefix('client/orders')->middleware([\App\Http\Middleware\ClientAuth::class])->group(function () {
    Route::get('/', [ClientOrderController::class, 'index'])->name('client.orders.index');
    Route::post('/', [ClientOrderController::class, 'store'])->name('client.orders.store');
    Route::get('/{id}', [ClientOrderController::class, 'show'])->whereNumber('id')->name('client.orders.show');
    Route::get('/{id}/track', [ClientOrderController::class, 'trackOrder'])->whereNumber('id')->name('client.orders.track');
    // Listar productos comprados (excluyendo órdenes pendientes o canceladas)
    Route::get('/purchased-products', [ClientOrderController::class, 'purchasedProducts'])->name('client.orders.purchased-products');
    Route::put('/{id}/cancel', [ClientOrderController::class, 'cancelOrder'])->whereNumber('id')->name('client.orders.cancel');
    Route::post('/{id}/payment', [ClientOrderController::class, 'createPayment'])->whereNumber('id')->name('client.orders.payment');
});

/*
|--------------------------------------------------------------------------
| CLIENT CART ROUTES
|--------------------------------------------------------------------------
*/

Route::prefix('client/cart')->middleware([\App\Http\Middleware\ClientAuth::class])->group(function () {
    Route::get('/', [ClientCartController::class, 'index'])->name('client.cart.index');
    Route::post('/add', [ClientCartController::class, 'addToCart'])->name('client.cart.add');
    Route::put('/update/{productId}', [ClientCartController::class, 'updateQuantity'])->name('client.cart.update');
    Route::delete('/remove/{productId}', [ClientCartController::class, 'removeFromCart'])->name('client.cart.remove');
    Route::delete('/clear', [ClientCartController::class, 'clearCart'])->name('client.cart.clear');
});

/*
|--------------------------------------------------------------------------
| USER ROUTES
|--------------------------------------------------------------------------
*/

Route::middleware(['jwt.auth', 'check.token.version', 'admin.only'])->group(function () {
    Route::apiResource('users', UserController::class);
});

/*
|--------------------------------------------------------------------------
| ROLE ROUTES
|--------------------------------------------------------------------------
*/

Route::middleware(['jwt.auth', 'check.token.version', 'admin.only'])->group(function () {
    Route::apiResource('roles', RoleController::class);
});

/*
|--------------------------------------------------------------------------
| PRODUCT ROUTES
|--------------------------------------------------------------------------
*/

Route::prefix('products')->group(function () {
    Route::get('/public', [ProductController::class, 'publicIndex'])->name('products.public.index');
    Route::post('/updateProduct/{id}', [ProductController::class, 'updateProduct'])->name('products.updateProduct');
});

Route::middleware(['jwt.auth', 'check.token.version', 'admin.only'])->group(function () {
    Route::apiResource('products', ProductController::class);
});

/*
|--------------------------------------------------------------------------
| DASHBOARD ROUTES (STAFF)
|--------------------------------------------------------------------------
*/

Route::middleware(['jwt.auth', 'check.token.version'])->group(function () {
    Route::get('dashboard/overview', [DashboardController::class, 'overview'])->name('dashboard.overview');
    Route::get('dashboard/sales', [DashboardController::class, 'salesAnalytics'])->name('dashboard.sales');
    Route::get('dashboard/inventory', [DashboardController::class, 'inventoryAnalytics'])->name('dashboard.inventory');
    Route::get('dashboard/customers', [DashboardController::class, 'customerAnalytics'])->name('dashboard.customers');
    Route::get('dashboard/financial', [DashboardController::class, 'financialAnalytics'])->name('dashboard.financial');
    Route::get('dashboard/performance', [DashboardController::class, 'performanceMetrics'])->name('dashboard.performance');
});

/*
|--------------------------------------------------------------------------
| ORDER MANAGEMENT ROUTES (STAFF)
|--------------------------------------------------------------------------
*/

Route::middleware(['jwt.auth', 'check.token.version'])->group(function () {
    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('orders/{id}', [OrderController::class, 'show'])->whereNumber('id')->name('orders.show');
    Route::patch('orders/{id}/status', [OrderController::class, 'updateStatus'])->whereNumber('id')->name('orders.update-status');
    Route::get('orders/statistics', [OrderController::class, 'getStatistics'])->name('orders.statistics');
    Route::get('orders/status/{status}', [OrderController::class, 'getByStatus'])->name('orders.by-status');
    Route::post('orders/search', [OrderController::class, 'search'])->name('orders.search');
});

/*
|--------------------------------------------------------------------------
| STOCK MOVEMENT ROUTES
|--------------------------------------------------------------------------
*/

Route::middleware(['jwt.auth', 'check.token.version', 'admin.only'])->group(function () {
    Route::apiResource('stock-movements', StockMovementController::class);
    Route::patch('stock-movements/{stockMovement}/cancel', [StockMovementController::class, 'cancel'])->name('stock-movements.cancel');
});

/*
|--------------------------------------------------------------------------
| REPORT ROUTES (STAFF)
|--------------------------------------------------------------------------
*/

Route::middleware(['jwt.auth', 'check.token.version'])->group(function () {
    Route::post('reports/sales', [App\Http\Controllers\ReportController::class, 'salesReport'])->name('reports.sales');
    Route::post('reports/purchases', [App\Http\Controllers\ReportController::class, 'purchasesReport'])->name('reports.purchases');
    Route::post('reports/orders', [App\Http\Controllers\ReportController::class, 'ordersReport'])->name('reports.orders');
});

/*
|--------------------------------------------------------------------------
| WEBHOOK ROUTES
|--------------------------------------------------------------------------
*/

// MercadoPago webhook (sin autenticación para permitir notificaciones)
Route::post('webhooks/mercadopago', [WebhookController::class, 'mercadoPago'])->name('webhooks.mercadopago');

// Payment status check (con autenticación para clientes)
Route::get('payment-status/{orderId}', [WebhookController::class, 'getPaymentStatus'])
    ->middleware([\App\Http\Middleware\ClientAuth::class])
    ->name('payment.status');

// Ruta de prueba para MercadoPago (solo desarrollo)
if (app()->environment('local')) {
    Route::get('test/mercadopago', function () {
        $paymentService = app(\App\Services\PaymentService::class);
        $result = $paymentService->testMercadoPagoConnection();

        return response()->json($result);
    })->name('test.mercadopago');
}


/*
|--------------------------------------------------------------------------
| DOCUMENT LOOKUP ROUTES
|--------------------------------------------------------------------------
*/

Route::post('document/lookup', [DocumentLookupController::class, 'lookup'])
    ->name('document.lookup');
