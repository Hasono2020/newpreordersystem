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
use App\Http\Controllers\ShippingAreaController;
use App\Http\Controllers\ReportController;

// Auth
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

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

    // Promos
    Route::resource('promos', PromoController::class)->except(['show']);

    // Purchasing
    Route::get('purchasing', [PurchasingController::class, 'index'])->name('purchasing.index');
    Route::post('purchasing', [PurchasingController::class, 'store'])->name('purchasing.store');
    Route::get('purchasing/{purchasing}', [PurchasingController::class, 'show'])->name('purchasing.show');
    Route::post('purchasing/{purchasing}/arrival', [PurchasingController::class, 'confirmArrival'])->name('purchasing.arrival');

    // Shipping Areas (specific routes BEFORE resource to avoid conflicts)
    Route::get('shipping/template', [ShippingAreaController::class, 'template'])->name('shipping.template');
    Route::get('shipping/export', [ShippingAreaController::class, 'export'])->name('shipping.export');
    Route::post('shipping/import', [ShippingAreaController::class, 'import'])->name('shipping.import');
    Route::resource('shipping', ShippingAreaController::class)->except(['show']);

    // Reports & Export
    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('reports/export/orders', [ReportController::class, 'exportOrders'])->name('reports.export.orders');
    Route::get('reports/export/items', [ReportController::class, 'exportOrderItems'])->name('reports.export.items');
    Route::get('reports/export/customers', [ReportController::class, 'exportCustomers'])->name('reports.export.customers');
    Route::get('reports/export/products', [ReportController::class, 'exportProducts'])->name('reports.export.products');
    Route::post('reports/import/customers', [ReportController::class, 'importCustomers'])->name('reports.import.customers');

    // AJAX APIs
    Route::get('api/trips/{trip}/products', [OrderController::class, 'tripProducts'])->name('api.trip.products');
    Route::get('api/customers/search', [OrderController::class, 'searchCustomers'])->name('api.customers.search');
    Route::post('api/customers/quick', [OrderController::class, 'quickCreateCustomer'])->name('api.customers.quick');
    Route::get('api/shipping/calc', [OrderController::class, 'calcShipping'])->name('api.shipping.calc');
    Route::get('api/shipping/areas', [ShippingAreaController::class, 'apiList'])->name('api.shipping.areas');
});
