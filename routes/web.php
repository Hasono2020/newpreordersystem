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
use App\Http\Controllers\StaffController;

// Auth
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::middleware('auth')->group(function () {

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Trips
    Route::resource('trips', TripController::class);

    // Products
    Route::get('products-export', [ProductController::class, 'export'])->name('products.export');
    Route::get('products-import-template', [ProductController::class, 'importTemplate'])->name('products.import.template');
    Route::post('products-import', [ProductController::class, 'importCsv'])->name('products.import');
    Route::post('products/bulk-destroy', [ProductController::class, 'bulkDestroy'])->name('products.bulk-destroy');
    Route::resource('products', ProductController::class);
    Route::post('products/{product}/variants', [ProductController::class, 'storeVariant'])->name('products.variants.store');
    Route::patch('products/{product}/variants/{variant}', [ProductController::class, 'updateVariant'])->name('products.variants.update');
    Route::delete('products/{product}/variants/{variant}', [ProductController::class, 'destroyVariant'])->name('products.variants.destroy');

    // Customers
    Route::resource('customers', CustomerController::class);
    Route::delete('customers-bulk', [CustomerController::class, 'bulkDestroy'])->name('customers.bulk-destroy');
    Route::get('customers-export', [CustomerController::class, 'export'])->name('customers.export');
    Route::get('customers-import-template', [CustomerController::class, 'importTemplate'])->name('customers.import.template');
    Route::post('customers-import', [CustomerController::class, 'importCsv'])->name('customers.import');

    // Orders
    Route::get('orders-export', [ReportController::class, 'exportOrders'])->name('orders.export');
    Route::get('orders-items-export', [ReportController::class, 'exportOrderItems'])->name('orders.items.export');
    Route::get('orders-import-template', [ReportController::class, 'orderImportTemplate'])->name('orders.import.template');
    Route::post('orders-import', [ReportController::class, 'importOrders'])->name('orders.import');
    Route::post('orders/bulk-destroy', [OrderController::class, 'bulkDestroy'])->name('orders.bulk-destroy');
    Route::resource('orders', OrderController::class);
    Route::post('orders/{order}/items', [OrderController::class, 'addItem'])->name('orders.items.add');
    Route::patch('orders/{order}/items/{item}', [OrderController::class, 'updateItem'])->name('orders.items.update');
    Route::patch('orders/{order}/items/{item}/status', [OrderController::class, 'updateItemStatus'])->name('orders.items.status');
    Route::delete('orders/{order}/items/{item}', [OrderController::class, 'removeItem'])->name('orders.items.remove');
    Route::post('orders/{order}/payments', [OrderController::class, 'addPayment'])->name('orders.payments.add');
    Route::post('payments/{payment}/void', [OrderController::class, 'voidPayment'])->name('payments.void');
    Route::get('orders/{order}/invoice', [OrderController::class, 'invoice'])->name('orders.invoice');
    Route::get('customers/{customer}/combined-invoice', [OrderController::class, 'combinedInvoice'])->name('orders.combined-invoice');

    // Promos
    Route::resource('promos', PromoController::class)->except(['show']);

    // Purchasing
    Route::get('purchasing', [PurchasingController::class, 'index'])->name('purchasing.index');
    Route::get('purchasing-demand', [PurchasingController::class, 'demandApi'])->name('purchasing.demand');
    Route::post('purchasing', [PurchasingController::class, 'store'])->name('purchasing.store');
    Route::get('purchasing/{purchasing}', [PurchasingController::class, 'show'])->name('purchasing.show');
    Route::get('purchasing/{purchasing}/edit', [PurchasingController::class, 'edit'])->name('purchasing.edit');
    Route::put('purchasing/{purchasing}', [PurchasingController::class, 'update'])->name('purchasing.update');
    Route::patch('purchasing/{purchasing}/item/{item}', [PurchasingController::class, 'updateItem'])->name('purchasing.item.update');
    Route::post('purchasing/{purchasing}/add-item', [PurchasingController::class, 'addItem'])->name('purchasing.item.add');
    Route::delete('purchasing/{purchasing}/item/{item}', [PurchasingController::class, 'deleteItem'])->name('purchasing.item.delete');
    Route::delete('purchasing/{purchasing}', [PurchasingController::class, 'destroy'])->name('purchasing.destroy');
    Route::post('purchasing/{purchasing}/arrival', [PurchasingController::class, 'confirmArrival'])->name('purchasing.arrival');

    // Shipping Areas
    Route::get('shipping/template', [ShippingAreaController::class, 'template'])->name('shipping.template');
    Route::get('shipping/export', [ShippingAreaController::class, 'export'])->name('shipping.export');
    Route::post('shipping/import', [ShippingAreaController::class, 'import'])->name('shipping.import');
    Route::delete('shipping/bulk-destroy', [ShippingAreaController::class, 'bulkDestroy'])->name('shipping.bulk-destroy');
    Route::resource('shipping', ShippingAreaController::class);

    // Reports & Export
    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('reports/export/orders', [ReportController::class, 'exportOrders'])->name('reports.export.orders');
    Route::get('reports/export/items', [ReportController::class, 'exportOrderItems'])->name('reports.export.items');
    Route::get('reports/export/customers', [ReportController::class, 'exportCustomers'])->name('reports.export.customers');
    Route::get('reports/export/products', [ReportController::class, 'exportProducts'])->name('reports.export.products');
    Route::get('reports/import/orders/template', [ReportController::class, 'orderImportTemplate'])->name('reports.import.orders.template');
    Route::post('reports/import/orders', [ReportController::class, 'importOrders'])->name('reports.import.orders');
    Route::post('reports/import/customers', [ReportController::class, 'importCustomers'])->name('reports.import.customers');

    // Suppliers
    Route::delete('suppliers/bulk-destroy', [\App\Http\Controllers\SupplierController::class, 'bulkDestroy'])->name('suppliers.bulk-destroy');
    Route::resource('suppliers', \App\Http\Controllers\SupplierController::class);

    // Store Settings (admin only)
    Route::get('settings', [\App\Http\Controllers\SettingsController::class, 'index'])->name('settings.index');
    Route::put('settings', [\App\Http\Controllers\SettingsController::class, 'update'])->name('settings.update');

    // Staff management (admin only)
    Route::get('staff', [StaffController::class, 'index'])->name('staff.index');
    Route::post('staff', [StaffController::class, 'store'])->name('staff.store');
    Route::put('staff/{staff}', [StaffController::class, 'update'])->name('staff.update');
    Route::delete('staff/{staff}', [StaffController::class, 'destroy'])->name('staff.destroy');

    Route::get('api/suppliers/search', [\App\Http\Controllers\SupplierController::class, 'search'])->name('api.suppliers.search');
    Route::post('api/suppliers/quick', [\App\Http\Controllers\SupplierController::class, 'quickStore'])->name('api.suppliers.quick');

    // AJAX APIs
    Route::get('api/products/check-code', [ProductController::class, 'checkCode'])->name('api.products.check-code');
    Route::get('api/trips/{trip}/products', [OrderController::class, 'tripProducts'])->name('api.trip.products');
    Route::get('api/customers/search', [OrderController::class, 'searchCustomers'])->name('api.customers.search');
    Route::post('api/customers/quick', [OrderController::class, 'quickCreateCustomer'])->name('api.customers.quick');
    Route::get('api/shipping/calc', [OrderController::class, 'calcShipping'])->name('api.shipping.calc');
    Route::get('api/shipping/areas', [ShippingAreaController::class, 'apiList'])->name('api.shipping.areas');
});