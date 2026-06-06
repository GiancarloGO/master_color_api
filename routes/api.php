<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChatbotController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\ClientController;
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

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\DiagnosticController;
use App\Http\Controllers\ClientSoldUnitController;
use App\Http\Controllers\SupportUnitController;
use App\Http\Controllers\ClientSupportTicketController;
use App\Http\Controllers\SupportTicketController;
use App\Http\Controllers\ClientDeviceController;
use App\Http\Controllers\SupportDeviceController;
use App\Http\Controllers\SupportMetricsController;
use App\Http\Controllers\SupportTechnicianController;
use App\Http\Controllers\ClientProductController;

/*
|--------------------------------------------------------------------------
| DIAGNOSTIC ROUTES (TEMPORARY - REMOVE AFTER DEBUGGING)
|--------------------------------------------------------------------------
*/
Route::get('/diagnostic', [DiagnosticController::class, 'index']);
Route::get('/diagnostic/test-email', [DiagnosticController::class, 'testEmail']);

/*
|--------------------------------------------------------------------------
| AUTH ROUTES
|--------------------------------------------------------------------------
*/

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->name('auth.register');
    Route::post('/login', [AuthController::class, 'login'])->name('auth.login');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->name('auth.forgot-password');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('auth.reset-password');

    // Rutas que requieren autenticación
    Route::middleware(['jwt.auth', 'check.token.version'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('/refresh', [AuthController::class, 'refresh'])->name('auth.refresh');
        Route::post('/me', [AuthController::class, 'me'])->name('auth.me');
        Route::get('/me', [AuthController::class, 'me'])->name('auth.me.get');
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
        Route::get('/me', [ClientAuthController::class, 'me'])->name('client.me.get');
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
| CLIENT PRODUCT CATALOG ROUTES
|--------------------------------------------------------------------------
*/

Route::prefix('client/products')->middleware([\App\Http\Middleware\ClientAuth::class])->group(function () {
    Route::get('/', [ClientProductController::class, 'index'])->name('client.products.index');
});

/*
|--------------------------------------------------------------------------
| USER ROUTES
|--------------------------------------------------------------------------
*/

Route::middleware(['jwt.auth', 'check.token.version', 'admin.only'])->group(function () {
    Route::apiResource('users', UserController::class);
    Route::post('users/{id}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
});

/*
|--------------------------------------------------------------------------
| CLIENT MANAGEMENT ROUTES (STAFF)
|--------------------------------------------------------------------------
*/

Route::middleware(['jwt.auth', 'check.token.version'])->group(function () {
    Route::apiResource('clients', ClientController::class);
    Route::get('clients-deleted', [ClientController::class, 'deleted'])->name('clients.deleted');
    Route::patch('clients/{id}/restore', [ClientController::class, 'restore'])->name('clients.restore');
    Route::delete('clients/{id}/force', [ClientController::class, 'forceDestroy'])->name('clients.force-destroy');
    Route::patch('clients/{id}/toggle-verification', [ClientController::class, 'toggleVerification'])->name('clients.toggle-verification');
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
| AUDIT LOG ROUTES (ADMIN ONLY)
|--------------------------------------------------------------------------
*/

Route::middleware(['jwt.auth', 'check.token.version', 'admin.only'])->group(function () {
    Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
    Route::get('audit-logs/{id}', [AuditLogController::class, 'show'])->name('audit-logs.show');
});

/*
|--------------------------------------------------------------------------
| DOCUMENT LOOKUP ROUTES
|--------------------------------------------------------------------------
*/

Route::post('document/lookup', [DocumentLookupController::class, 'lookup'])
    ->name('document.lookup');

/*
|--------------------------------------------------------------------------
| CHATBOT ROUTES (PUBLIC)
|--------------------------------------------------------------------------
*/

Route::prefix('chatbot')->middleware('throttle:20,1')->group(function () {
    Route::post('message', [ChatbotController::class, 'message'])->name('chatbot.message');
});

/*
|--------------------------------------------------------------------------
| SUPPORT — SOLD UNITS / WARRANTY (CLIENT)
|--------------------------------------------------------------------------
*/

Route::prefix('client/units')->middleware([\App\Http\Middleware\ClientAuth::class])->group(function () {
    Route::get('/', [ClientSoldUnitController::class, 'index'])->name('client.units.index');
    Route::post('/', [ClientSoldUnitController::class, 'store'])->name('client.units.store');
    Route::get('/{id}', [ClientSoldUnitController::class, 'show'])->whereNumber('id')->name('client.units.show');
    Route::get('/{id}/warranty', [ClientSoldUnitController::class, 'warranty'])->whereNumber('id')->name('client.units.warranty');
});

/*
|--------------------------------------------------------------------------
| SUPPORT — SOLD UNITS (STAFF)
|--------------------------------------------------------------------------
*/

Route::prefix('support/units')->middleware(['jwt.auth', 'check.token.version'])->group(function () {
    Route::get('/', [SupportUnitController::class, 'index'])->name('support.units.index');
    Route::get('/{id}', [SupportUnitController::class, 'show'])->whereNumber('id')->name('support.units.show');
    Route::patch('/{id}', [SupportUnitController::class, 'update'])->whereNumber('id')->name('support.units.update');
});

/*
|--------------------------------------------------------------------------
| SUPPORT — METRICS (STAFF)
|--------------------------------------------------------------------------
*/

Route::middleware(['jwt.auth', 'check.token.version'])->group(function () {
    Route::get('support/metrics', [SupportMetricsController::class, 'index'])->name('support.metrics');
});

/*
|--------------------------------------------------------------------------
| SUPPORT — TECHNICIANS (STAFF)
|--------------------------------------------------------------------------
*/

Route::middleware(['jwt.auth', 'check.token.version'])->group(function () {
    Route::get('support/technicians', [SupportTechnicianController::class, 'index'])->name('support.technicians');
});

/*
|--------------------------------------------------------------------------
| SUPPORT — TICKETS (CLIENT)
|--------------------------------------------------------------------------
*/

Route::prefix('client/support/tickets')->middleware([\App\Http\Middleware\ClientAuth::class])->group(function () {
    Route::get('/', [ClientSupportTicketController::class, 'index'])->name('client.tickets.index');
    Route::post('/', [ClientSupportTicketController::class, 'store'])->name('client.tickets.store');
    Route::get('/{id}', [ClientSupportTicketController::class, 'show'])->whereNumber('id')->name('client.tickets.show');
    Route::post('/{id}/messages', [ClientSupportTicketController::class, 'messages'])->whereNumber('id')->name('client.tickets.messages');
    Route::post('/{id}/attachments', [ClientSupportTicketController::class, 'attachments'])->whereNumber('id')->name('client.tickets.attachments');
    Route::post('/{id}/rate', [ClientSupportTicketController::class, 'rate'])->whereNumber('id')->name('client.tickets.rate');
    Route::put('/{id}/reopen', [ClientSupportTicketController::class, 'reopen'])->whereNumber('id')->name('client.tickets.reopen');
});

/*
|--------------------------------------------------------------------------
| SUPPORT — TICKETS (STAFF)
|--------------------------------------------------------------------------
*/

Route::prefix('support/tickets')->middleware(['jwt.auth', 'check.token.version'])->group(function () {
    Route::get('/', [SupportTicketController::class, 'index'])->name('support.tickets.index');
    Route::get('/mine', [SupportTicketController::class, 'mine'])->name('support.tickets.mine');
    Route::get('/{id}', [SupportTicketController::class, 'show'])->whereNumber('id')->name('support.tickets.show');
    Route::patch('/{id}/assign', [SupportTicketController::class, 'assign'])->whereNumber('id')->name('support.tickets.assign');
    Route::patch('/{id}/status', [SupportTicketController::class, 'status'])->whereNumber('id')->name('support.tickets.status');
    Route::post('/{id}/messages', [SupportTicketController::class, 'messages'])->whereNumber('id')->name('support.tickets.messages');
    Route::post('/{id}/attachments', [SupportTicketController::class, 'attachments'])->whereNumber('id')->name('support.tickets.attachments');
    Route::post('/{id}/diagnosis', [SupportTicketController::class, 'diagnosis'])->whereNumber('id')->name('support.tickets.diagnosis');
});

/*
|--------------------------------------------------------------------------
| SUPPORT — DEVICE TOKENS (PUSH FCM)
|--------------------------------------------------------------------------
*/

Route::prefix('client/devices')->middleware([\App\Http\Middleware\ClientAuth::class])->group(function () {
    Route::post('/', [ClientDeviceController::class, 'store'])->name('client.devices.store');
    Route::delete('/{token}', [ClientDeviceController::class, 'destroy'])->name('client.devices.destroy');
});

Route::prefix('support/devices')->middleware(['jwt.auth', 'check.token.version'])->group(function () {
    Route::post('/', [SupportDeviceController::class, 'store'])->name('support.devices.store');
    Route::delete('/{token}', [SupportDeviceController::class, 'destroy'])->name('support.devices.destroy');
});
