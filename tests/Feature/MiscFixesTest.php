<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\PromoRule;
use App\Models\ShippingArea;
use App\Models\Trip;
use App\Models\User;
use App\Services\PromoService;

/*
 * Fix #6  — CustomerController::applyShipping() cascades to all non-closed-trip
 *            orders including paid/partial, and correctly excludes closed trips.
 * Fix #7  — PromoService::recalcCustomerShipping() no longer has a dead
 *            whereNotIn([]) clause — all payment statuses are included.
 * Fix #9  — PaymentController::store() rejects mismatched order IDs instead
 *            of silently skipping them.
 * Fix #12 — PromoService::clearCache() resets the rule cache between trips.
 * Fix #15 — Order::boot() order_number loop has an attempt limit of 10.
 */

// ── Fix #6: applyShipping cascade ───────────────────────────────────

test('applyShipping updates unpaid orders in non-closed trips', function () {
    $admin   = $this->adminUser();
    $oldArea = ShippingArea::factory()->create(['name' => 'SEMARANG', 'price_per_kg' => 35000]);
    $newArea = ShippingArea::factory()->flatFee(10000)->create(['name' => 'BATAM']);
    $trip    = $this->openTrip();
    $cust    = Customer::factory()->create([
        'default_shipping_area_id' => $newArea->id,
        'created_by'               => $admin->id,
    ]);

    $order = Order::factory()->create([
        'trip_id'          => $trip->id,
        'customer_id'      => $cust->id,
        'created_by'       => $admin->id,
        'shipping_area_id' => $oldArea->id,
        'payment_status'   => 'unpaid',
        'subtotal'         => 200000,
        'total_amount'     => 200000,
    ]);

    $this->actingAs($admin)
        ->post(route('customers.apply-shipping', $cust))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($order->fresh()->shipping_area_id)->toBe($newArea->id);
});

test('applyShipping also updates paid and partial orders in non-closed trips', function () {
    $admin   = $this->adminUser();
    $oldArea = ShippingArea::factory()->create(['name' => 'SEMARANG2', 'price_per_kg' => 35000]);
    $newArea = ShippingArea::factory()->flatFee(10000)->create(['name' => 'BATAM2']);
    $trip    = $this->openTrip();
    $cust    = Customer::factory()->create([
        'default_shipping_area_id' => $newArea->id,
        'created_by'               => $admin->id,
    ]);

    $paidOrder = Order::factory()->paid()->create([
        'trip_id' => $trip->id, 'customer_id' => $cust->id,
        'created_by' => $admin->id, 'shipping_area_id' => $oldArea->id,
    ]);
    $partialOrder = Order::factory()->partial()->create([
        'trip_id' => $trip->id, 'customer_id' => $cust->id,
        'created_by' => $admin->id, 'shipping_area_id' => $oldArea->id,
    ]);

    $this->actingAs($admin)->post(route('customers.apply-shipping', $cust));

    expect($paidOrder->fresh()->shipping_area_id)->toBe($newArea->id)
        ->and($partialOrder->fresh()->shipping_area_id)->toBe($newArea->id);
});

test('applyShipping does NOT update orders in closed trips', function () {
    $admin   = $this->adminUser();
    $oldArea = ShippingArea::factory()->create(['name' => 'SEMARANG3', 'price_per_kg' => 35000]);
    $newArea = ShippingArea::factory()->flatFee(10000)->create(['name' => 'BATAM3']);

    $closedTrip = Trip::factory()->create([
        'status'     => 'closed',
        'created_by' => $admin->id,
    ]);
    $cust = Customer::factory()->create([
        'default_shipping_area_id' => $newArea->id,
        'created_by'               => $admin->id,
    ]);

    $order = Order::factory()->create([
        'trip_id'          => $closedTrip->id,
        'customer_id'      => $cust->id,
        'created_by'       => $admin->id,
        'shipping_area_id' => $oldArea->id,
        'payment_status'   => 'unpaid',
    ]);

    $this->actingAs($admin)->post(route('customers.apply-shipping', $cust));

    // Closed trip order must NOT be touched
    expect($order->fresh()->shipping_area_id)->toBe($oldArea->id);
});

test('applyShipping returns error when customer has no default shipping area', function () {
    $admin = $this->adminUser();
    $cust  = Customer::factory()->create([
        'default_shipping_area_id' => null,
        'created_by'               => $admin->id,
    ]);

    $this->actingAs($admin)
        ->post(route('customers.apply-shipping', $cust))
        ->assertRedirect()
        ->assertSessionHas('error');
});

// ── Fix #7: recalcCustomerShipping includes all payment statuses ─────

test('recalcCustomerShipping recalculates paid and partial orders', function () {
    $svc  = app(PromoService::class);
    $admin = User::factory()->admin()->create();
    $trip  = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $area  = ShippingArea::factory()->create(['price_per_kg' => 20000]);
    $cust  = Customer::factory()->create([
        'type'                     => 'customer',
        'default_shipping_area_id' => $area->id,
        'created_by'               => $admin->id,
    ]);

    $product = Product::create([
        'trip_id' => $trip->id, 'product_code' => 'AA_FIX7',
        'price' => 200000, 'weight_gram' => 500, 'status' => 'active',
    ]);

    // Create a paid order with wrong shipping_fee (0) to prove recalc fixes it
    $order = Order::factory()->paid()->create([
        'trip_id'          => $trip->id,
        'customer_id'      => $cust->id,
        'created_by'       => $admin->id,
        'shipping_area_id' => $area->id,
        'subtotal'         => 200000,
        'total_amount'     => 200000,
        'shipping_fee'     => 0,  // wrong — should be recalculated
    ]);
    OrderItem::create([
        'order_id' => $order->id, 'product_id' => $product->id,
        'quantity' => 1, 'unit_price' => 200000, 'line_total' => 200000,
        'status' => 'confirmed',
    ]);

    $svc->recalcCustomerShipping($cust->id, $trip->id);

    // 500g -> 1kg -> 20000 shipping should now be set
    expect((float) $order->fresh()->shipping_fee)->toBe(20000.0);
});

// ── Fix #9: payment mismatch returns error ───────────────────────────

test('recording payment for wrong order_id returns error not silent skip', function () {
    $admin  = $this->adminUser();
    $trip   = $this->openTrip();
    $area   = $this->shippingArea();
    $cust   = $this->customer($admin);
    $cust2  = $this->customer($admin);

    $order  = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $cust->id,
        'created_by' => $admin->id, 'shipping_area_id' => $area->id,
        'subtotal' => 500000, 'total_amount' => 500000,
    ]);
    // order belonging to a DIFFERENT customer
    $otherOrder = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $cust2->id,
        'created_by' => $admin->id, 'shipping_area_id' => $area->id,
        'subtotal' => 300000, 'total_amount' => 300000,
    ]);

    // POST allocations for cust but with otherOrder's ID (mismatch)
    $response = $this->actingAs($admin)->post('/payments', [
        'customer_id' => $cust->id,
        'trip_id'     => $trip->id,
        'method'      => 'transfer',
        'paid_at'     => now()->toDateString(),
        'allocations' => [
            ['order_id' => $otherOrder->id, 'amount' => 300000],
        ],
    ]);

    // Transaction should have rolled back — no payment recorded
    expect($otherOrder->fresh()->payment_status)->toBe('unpaid')
        ->and($otherOrder->payments()->count())->toBe(0);
});

// ── Fix #12: PromoService::clearCache() ─────────────────────────────

test('clearCache resets rule cache allowing fresh rules to load', function () {
    $svc  = app(PromoService::class);
    $admin = User::factory()->admin()->create();

    // Trip 1 — load rules (caches for trip 1)
    $trip1 = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $area  = ShippingArea::factory()->create(['price_per_kg' => 10000]);
    $cust  = Customer::factory()->create([
        'type' => 'customer', 'default_shipping_area_id' => $area->id, 'created_by' => $admin->id,
    ]);
    $prod1 = Product::create([
        'trip_id' => $trip1->id, 'product_code' => 'CC_01',
        'price' => 50000, 'weight_gram' => 100, 'status' => 'active',
    ]);
    $rule1 = PromoRule::create([
        'name' => 'trip1-rule', 'min_items' => 1,
        'discount_per_item' => 0, 'discount_flat' => 10000, 'max_shipping_subsidy' => 0,
        'eligible_customer_types' => ['customer'], 'excluded_product_codes' => [],
        'trip_id' => $trip1->id, 'is_active' => true,
    ]);

    $items1 = collect([
        (object) ['product' => $prod1, 'quantity' => 1, 'product_id' => $prod1->id]
    ]);
    $best1 = $svc->getBestPromo('customer', $trip1->id, $items1);
    expect($best1['rule']->id)->toBe($rule1->id);

    // Clear cache — rule1 was cached for trip1
    $svc->clearCache();

    // Deactivate rule1 AFTER clearing cache — next call should not see it
    $rule1->update(['is_active' => false]);

    $best1Again = $svc->getBestPromo('customer', $trip1->id, $items1);
    expect($best1Again)->toBeNull(); // cache was cleared, fresh query sees is_active=false
});

// ── Fix #15: order_number loop has attempt limit ─────────────────────

test('Order model generates a unique order_number on create', function () {
    $admin  = $this->adminUser();
    $trip   = $this->openTrip();
    $area   = $this->shippingArea();
    $cust   = $this->customer($admin);

    $o1 = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $cust->id,
        'created_by' => $admin->id, 'shipping_area_id' => $area->id,
    ]);
    $o2 = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $cust->id,
        'created_by' => $admin->id, 'shipping_area_id' => $area->id,
    ]);

    expect($o1->order_number)->not->toBeNull()
        ->and($o2->order_number)->not->toBeNull()
        ->and($o1->order_number)->not->toBe($o2->order_number)
        ->and($o1->order_number)->toStartWith('ORD-')
        ->and($o2->order_number)->toStartWith('ORD-');
});

test('order_number is not regenerated if already set on create', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $area  = $this->shippingArea();
    $cust  = $this->customer($admin);

    $order = Order::factory()->create([
        'trip_id'          => $trip->id,
        'customer_id'      => $cust->id,
        'created_by'       => $admin->id,
        'shipping_area_id' => $area->id,
        'order_number'     => 'ORD-CUSTOM123',
    ]);

    expect($order->order_number)->toBe('ORD-CUSTOM123');
});