<?php
use App\Models\Product;
use App\Models\Supplier;

function makeSupplier(): Supplier
{
    return Supplier::create(['name' => 'Test Supplier', 'contact' => null, 'notes' => null]);
}

test('product requires product code', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();

    $this->actingAs($admin)->post('/products', [
        'trip_id'     => $trip->id,
        'supplier_id' => makeSupplier()->id,
        'name'        => 'Test Product',
        'price'       => 100000,
        'weight_gram' => 350,
        // product_code intentionally missing
    ])->assertSessionHasErrors('product_code');
});

test('product requires weight', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();

    $this->actingAs($admin)->post('/products', [
        'trip_id'      => $trip->id,
        'supplier_id'  => makeSupplier()->id,
        'name'         => 'Test Product',
        'product_code' => 'TEST_01',
        'price'        => 100000,
        // weight_gram intentionally missing
    ])->assertSessionHasErrors('weight_gram');
});

test('product weight must be at least 1', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();

    $this->actingAs($admin)->post('/products', [
        'trip_id'      => $trip->id,
        'supplier_id'  => makeSupplier()->id,
        'name'         => 'Test Product',
        'product_code' => 'TEST_01',
        'price'        => 100000,
        'weight_gram'  => 0,
    ])->assertSessionHasErrors('weight_gram');
});

test('product code must be unique per trip', function () {
    $admin   = $this->adminUser();
    $trip    = $this->openTrip();
    $supplier = makeSupplier();

    // Create first product
    $this->actingAs($admin)->post('/products', [
        'trip_id'      => $trip->id,
        'supplier_id'  => $supplier->id,
        'name'         => 'Product 1',
        'product_code' => 'DUPE_01',
        'price'        => 100000,
        'weight_gram'  => 350,
    ])->assertRedirect();

    // Duplicate code in same trip
    $this->actingAs($admin)->post('/products', [
        'trip_id'      => $trip->id,
        'supplier_id'  => $supplier->id,
        'name'         => 'Product 2',
        'product_code' => 'DUPE_01',
        'price'        => 150000,
        'weight_gram'  => 400,
    ])->assertSessionHasErrors('product_code');
});
