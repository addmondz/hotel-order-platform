<?php

use App\Http\Controllers\OrderDeskController;
use App\Http\Controllers\StaffAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $path = public_path('dist/index.html');
    abort_unless(is_file($path), 503, 'Build the Vue workspace with npm run build before opening the app.');

    return response()->file($path, ['Cache-Control' => 'no-cache']);
});

Route::prefix('api')->group(function () {
    Route::get('/csrf', [StaffAuthController::class, 'csrf']);
    Route::post('/login', [StaffAuthController::class, 'login']);

    Route::middleware('auth')->group(function () {
        Route::get('/me', [StaffAuthController::class, 'me']);
        Route::post('/logout', [StaffAuthController::class, 'logout']);
        Route::get('/customers', [OrderDeskController::class, 'listCustomers']);
        Route::post('/customers', [OrderDeskController::class, 'createCustomer']);
        Route::put('/customers/{id}', [OrderDeskController::class, 'updateCustomer'])->whereNumber('id');

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
    });
});
