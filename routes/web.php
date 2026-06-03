<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\TripController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PromoController;
use App\Http\Controllers\PurchasingController;

// Auth routes
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Protected routes
Route::middleware('auth')->group(function () {

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Trips
    Route::resource('trips', TripController::class);

    // Products
    Route::resource('products', ProductController::class);
    Route::post('products/{product}/variants', [ProductController::class, 'storeVariant'])->name('products.variants.store');
    Route::patch('products/{product}/variants/{variant}', [ProductController::class, 'updateVariant'])->name('products.variants.update');
    Route::delete('products/{product}/variants/{variant}', [ProductController::class, 'destroyVariant'])->name('products.variants.destroy');

    // Customers
    Route::resource('customers', CustomerController::class);

    // Orders
    Route::resource('orders', OrderController::class);
    Route::post('orders/{order}/items', [OrderController::class, 'addItem'])->name('orders.items.add');
    Route::patch('orders/{order}/items/{item}/status', [OrderController::class, 'updateItemStatus'])->name('orders.items.status');
    Route::delete('orders/{order}/items/{item}', [OrderController::class, 'removeItem'])->name('orders.items.remove');
    Route::post('orders/{order}/payments', [OrderController::class, 'addPayment'])->name('orders.payments.add');
    Route::get('api/trips/{trip}/products', [OrderController::class, 'tripProducts'])->name('api.trip.products');

    // Promos
    Route::resource('promos', PromoController::class)->except(['show']);

    // Purchasing
    Route::get('purchasing', [PurchasingController::class, 'index'])->name('purchasing.index');
    Route::post('purchasing', [PurchasingController::class, 'store'])->name('purchasing.store');
    Route::get('purchasing/{purchasing}', [PurchasingController::class, 'show'])->name('purchasing.show');
    Route::post('purchasing/{purchasing}/arrival', [PurchasingController::class, 'confirmArrival'])->name('purchasing.arrival');

});
