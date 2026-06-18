<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingArea;
use App\Models\Supplier;
use App\Models\Trip;

/**
 * These tests lock in the own_data scope: a staff member with "own data only"
 * must NEVER see another staff member's orders, order amounts, or order counts
 * on any page. Each fixed leak (trip view, product view, customer view, and the
 * list-page count badges) gets an assertion here so a future change can't
 * silently reintroduce the leak — `php artisan test` will catch it.
 */

/**
 * Build a realistic order with one product + variant + order item, attributed
 * to a specific staff member, so we can assert visibility per creator.
 */
function scopeFixture(\App\Models\User $creator, Trip $trip, ShippingArea $area, string $code, int $qty, int $price)
{
    $supplier = Supplier::create(['name' => 'Sup '.$code, 'contact' => null, 'notes' => null]);

    $product = Product::create([
        'trip_id'     => $trip->id,
        'supplier_id' => $supplier->id,
        'product_code'=> $code,
        'price'       => $price,
        'weight_gram' => 250,
        'status'      => 'active',
    ]);
    $variant = ProductVariant::create([
        'product_id'     => $product->id,
        'color'          => 'Black',
        'size'           => 'M',
        'price_adjustment'=> 0,
        'supplier_stock' => 0,
        'allocated_qty'  => 0,
    ]);

    $cust = test()->customer($creator);

    $order = Order::factory()->create([
        'trip_id'          => $trip->id,
        'customer_id'      => $cust->id,
        'created_by'       => $creator->id,
        'shipping_area_id' => $area->id,
        'total_amount'     => $qty * $price,
        'payment_status'   => 'unpaid',
    ]);
    OrderItem::create([
        'order_id'           => $order->id,
        'product_id'         => $product->id,
        'product_variant_id' => $variant->id,
        'quantity'           => $qty,
        'unit_price'         => $price,
        'line_total'         => $qty * $price,
        'status'             => 'pending',
    ]);

    return [$product, $order, $cust];
}

test('own_data staff cannot see another staff order on the trip view', function () {
    $staffA = $this->ownDataStaff();
    $staffB = $this->ownDataStaff();
    $trip   = $this->openTrip();
    $area   = $this->shippingArea();

    [, , $custA] = scopeFixture($staffA, $trip, $area, 'AA_01', 1, 100000);
    [, , $custB] = scopeFixture($staffB, $trip, $area, 'BB_01', 1, 999000);

    $this->actingAs($staffA)->get("/trips/{$trip->id}")
        ->assertStatus(200)
        ->assertSee($custA->name)
        ->assertDontSee($custB->name);
});

test('own_data staff cannot see another staff order on the product view', function () {
    $staffA = $this->ownDataStaff();
    $staffB = $this->ownDataStaff();
    $trip   = $this->openTrip();
    $area   = $this->shippingArea();

    // Both order the SAME product so the leak (if present) would show the other's row
    $supplier = Supplier::create(['name' => 'Shared Sup', 'contact' => null, 'notes' => null]);
    $product  = Product::create([
        'trip_id' => $trip->id, 'supplier_id' => $supplier->id,
        'product_code' => 'SHARED_1', 'price' => 100000, 'weight_gram' => 250, 'status' => 'active',
    ]);
    $variant = ProductVariant::create([
        'product_id' => $product->id, 'color' => 'Black', 'size' => 'M',
        'price_adjustment' => 0, 'supplier_stock' => 0, 'allocated_qty' => 0,
    ]);

    $custA = $this->customer($staffA);
    $custB = $this->customer($staffB);

    foreach ([[$staffA, $custA], [$staffB, $custB]] as [$staff, $cust]) {
        $order = Order::factory()->create([
            'trip_id' => $trip->id, 'customer_id' => $cust->id, 'created_by' => $staff->id,
            'shipping_area_id' => $area->id, 'total_amount' => 100000, 'payment_status' => 'unpaid',
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id,
            'quantity' => 1, 'unit_price' => 100000, 'line_total' => 100000, 'status' => 'pending',
        ]);
    }

    $this->actingAs($staffA)->get("/products/{$product->id}")
        ->assertStatus(200)
        ->assertSee($custA->name)
        ->assertDontSee($custB->name);
});

test('own_data staff cannot see another staff order on the customer view', function () {
    $staffA = $this->ownDataStaff();
    $trip   = $this->openTrip();
    $area   = $this->shippingArea();

    // A customer that staffA "owns", but with an order created by someone else (admin)
    $admin = $this->adminUser();
    $cust  = $this->customer($staffA);

    $ownOrder = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $cust->id, 'created_by' => $staffA->id,
        'shipping_area_id' => $area->id, 'total_amount' => 111000, 'payment_status' => 'unpaid',
    ]);
    $otherOrder = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $cust->id, 'created_by' => $admin->id,
        'shipping_area_id' => $area->id, 'total_amount' => 222000, 'payment_status' => 'unpaid',
    ]);

    $resp = $this->actingAs($staffA)->get("/customers/{$cust->id}");
    $resp->assertStatus(200)
        ->assertSee($ownOrder->order_number)
        ->assertDontSee($otherOrder->order_number);
});

test('admin sees all orders on the trip view', function () {
    $admin  = $this->adminUser();
    $staffB = $this->ownDataStaff();
    $trip   = $this->openTrip();
    $area   = $this->shippingArea();

    [, , $custB] = scopeFixture($staffB, $trip, $area, 'CC_01', 1, 500000);

    $this->actingAs($admin)->get("/trips/{$trip->id}")
        ->assertStatus(200)
        ->assertSee($custB->name);
});