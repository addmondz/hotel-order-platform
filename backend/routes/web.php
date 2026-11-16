<?php

use App\Http\Controllers\HotelPortalAccountController;
use App\Http\Controllers\HotelPortalController;
use App\Http\Controllers\OrderDeskController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\StaffAuthController;
use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

$spa = function () {
    $path = public_path('dist/index.html');
    abort_unless(is_file($path), 503, 'Build the Vue workspace with npm run build before opening the app.');

    return response()->file($path, ['Cache-Control' => 'no-cache']);
};
foreach ([
    '/',
    '/customer',
    '/customer/catalog',
    '/customer/orders',
    '/customer/transactions',
    '/admin',
    '/admin/orders',
    '/admin/orders/new',
    '/admin/hotels',
    '/admin/hotels/new',
    '/admin/products',
    '/admin/products/new',
    '/admin/prices',
] as $path) {
    Route::get($path, $spa);
}

foreach ([
    '/customer/orders/{id}',
    '/admin/orders/{id}',
    '/admin/orders/{id}/edit',
    '/admin/hotels/{id}/edit',
    '/admin/products/{id}/edit',
] as $path) {
    Route::get($path, $spa)->where('id', '[1-9][0-9]*');
}

Route::prefix('api')->group(function () {
    Route::get('/csrf', [StaffAuthController::class, 'csrf']);
    Route::post('/login', [StaffAuthController::class, 'login']);
    Route::post('/portal/login', [StaffAuthController::class, 'hotelLogin']);

    Route::middleware('auth')->group(function () {
        Route::get('/me', [StaffAuthController::class, 'me']);
        Route::post('/logout', [StaffAuthController::class, 'logout']);
    });

    Route::middleware(['auth', 'account.role:hotel'])->prefix('portal')->group(function () {
        Route::get('/catalog', [HotelPortalController::class, 'catalog']);
        Route::get('/orders', [HotelPortalController::class, 'index']);
        Route::post('/orders', [HotelPortalController::class, 'store']);
        Route::get('/orders/{id}', [HotelPortalController::class, 'show'])->whereNumber('id');
        Route::get('/transactions', [TransactionController::class, 'portal']);
    });

    Route::middleware(['auth', 'account.role:staff'])->group(function () {
        Route::get('/customers', [OrderDeskController::class, 'listCustomers']);
        Route::post('/customers', [OrderDeskController::class, 'createCustomer']);
        Route::put('/customers/{id}', [OrderDeskController::class, 'updateCustomer'])->whereNumber('id');
        Route::get('/customers/{id}/portal-account', [HotelPortalAccountController::class, 'show'])->whereNumber('id');
        Route::put('/customers/{id}/portal-account', [HotelPortalAccountController::class, 'save'])->whereNumber('id');
        Route::get('/customers/{id}/transactions', [TransactionController::class, 'staff'])->whereNumber('id');

        Route::get('/products', [OrderDeskController::class, 'listProducts']);
        Route::post('/products', [OrderDeskController::class, 'createProduct']);
        Route::put('/products/{id}', [OrderDeskController::class, 'updateProduct'])->whereNumber('id');

        Route::get('/prices', [OrderDeskController::class, 'listPrices']);
        Route::put('/prices', [OrderDeskController::class, 'savePrice']);
        Route::delete('/prices', [OrderDeskController::class, 'deletePrice']);

        Route::get('/orders', [OrderDeskController::class, 'listOrders']);
        Route::post('/orders', [OrderDeskController::class, 'createOrder']);
        Route::get('/orders/{id}', [OrderDeskController::class, 'showOrder'])->whereNumber('id');
        Route::put('/orders/{id}', [OrderDeskController::class, 'updateOrder'])->whereNumber('id');
        Route::get('/orders/{id}/history', [OrderDeskController::class, 'orderHistory'])->whereNumber('id');
        Route::post('/orders/{id}/invoice', [OrderDeskController::class, 'invoiceOrder'])->whereNumber('id');
        Route::post('/orders/{id}/cancel', [OrderDeskController::class, 'cancelOrder'])->whereNumber('id');
        Route::get('/orders/{id}/payments', [PaymentController::class, 'index'])->whereNumber('id');
        Route::post('/orders/{id}/payments', [PaymentController::class, 'store'])->whereNumber('id');
        Route::get('/orders/{id}/payments/{payment}/receipt', [PaymentController::class, 'receipt'])
            ->whereNumber(['id', 'payment'])->name('invoice-payments.receipt');
    });
});
