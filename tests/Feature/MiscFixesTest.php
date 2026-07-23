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
 * Fix #6  — CustomerController::applyShipping() cascades area to all
 *            non-closed-trip orders including paid/partial.
 * Fix #7  — PromoService::recalcCustomerShipping() includes all payment statuses.
 * Fix #9  — PaymentController::store() rejects mismatched order IDs.
 * Fix #12 — PromoService::clearCache() resets rule cache between trips.
 * Fix #15 — Order::boot() order_number loop has an attempt limit.
 */

// ── Fix #6: applyShipping cascade ───────────────────────────────────

test('applyShipping updates unpaid orders in non-closed trips', function () {
    $admin   = $this->adminUser();
    $oldArea = ShippingArea::factory()->create(['name' => 'SEMARANG', 'price_per_kg' => 35000]);
    $newArea = ShippingArea::factory()->flatFee(10000)->create(['name' => 'BATAM']);
    $trip    = $this->openTrip();

    // Create customer with $newArea as default — don't let factory create its own area
    $cust = Customer::factory()->create([
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

    $cust = Customer::factory()->create([
        'default_shipping_area_id' => $newArea->id,
        'created_by'               => $admin->id,
    ]);

    $paidOrder = Order::factory()->paid()->create([
        'trip_id'          => $trip->id,
        'customer_id'      => $cust->id,
        'created_by'       => $admin->id,
        'shipping_area_id' => $oldArea->id,
    ]);
    $partialOrder = Order::factory()->partial()->create([
        'trip_id'          => $trip->id,
        'customer_id'      => $cust->id,
        'created_by'       => $admin->id,
        'shipping_area_id' => $oldArea->id,
    ]);

    $this->actingAs($admin)
        ->post(route('customers.apply-shipping', $cust));

    // Refresh from DB — applyShipping should have updated both orders to $newArea
    expect(Order::find($paidOrder->id)->shipping_area_id)->toBe($newArea->id)
        ->and(Order::find($partialOrder->id)->shipping_area_id)->toBe($newArea->id);
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

    $this->actingAs($admin)
        ->post(route('customers.apply-shipping', $cust));

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
    $svc   = app(PromoService::class);
    $admin = User::factory()->admin()->create();
    $trip  = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $area  = ShippingArea::factory()->create(['price_per_kg' => 20000]);
    $cust  = Customer::factory()->create([
        'type'                     => 'customer',
        'default_shipping_area_id' => $area->id,
        'created_by'               => $admin->id,
    ]);

    $product = Product::create([
        'trip_id'      => $trip->id,
        'product_code' => 'AA_FIX7',
        'price'        => 200000,
        'weight_gram'  => 500,
        'status'       => 'active',
    ]);

    $order = Order::factory()->paid()->create([
        'trip_id'          => $trip->id,
        'customer_id'      => $cust->id,
        'created_by'       => $admin->id,
        'shipping_area_id' => $area->id,
        'subtotal'         => 200000,
        'total_amount'     => 200000,
        'shipping_fee'     => 0, // wrong — should be recalculated
    ]);
    OrderItem::create([
        'order_id'   => $order->id,
        'product_id' => $product->id,
        'quantity'   => 1,
        'unit_price' => 200000,
        'line_total' => 200000,
        'status'     => 'confirmed',
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

    $otherOrder = Order::factory()->create([
        'trip_id'          => $trip->id,
        'customer_id'      => $cust2->id,
        'created_by'       => $admin->id,
        'shipping_area_id' => $area->id,
        'subtotal'         => 300000,
        'total_amount'     => 300000,
    ]);

    // POST allocations for cust but with otherOrder's ID (belongs to cust2)
    $this->actingAs($admin)
        ->post('/payments', [
            'customer_id' => $cust->id,
            'trip_id'     => $trip->id,
            'method'      => 'transfer',
            'paid_at'     => now()->toDateString(),
            'allocations' => [
                ['order_id' => $otherOrder->id, 'amount' => 300000],
            ],
        ]);

    // Transaction rolled back — no payment recorded
    expect($otherOrder->fresh()->payment_status)->toBe('unpaid')
        ->and($otherOrder->payments()->count())->toBe(0);
});

// ── Fix #12: PromoService::clearCache() ─────────────────────────────

test('clearCache resets rule cache allowing fresh rules to load', function () {
    $svc   = app(PromoService::class);
    $admin = User::factory()->admin()->create();
    $trip  = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $area  = ShippingArea::factory()->create(['price_per_kg' => 10000]);
    $prod  = Product::create([
        'trip_id'      => $trip->id,
        'product_code' => 'CC_01',
        'price'        => 50000,
        'weight_gram'  => 100,
        'status'       => 'active',
    ]);

    $rule = PromoRule::create([
        'name'                    => 'trip1-rule',
        'min_items'               => 1,
        'discount_per_item'       => 0,
        'discount_flat'           => 10000,
        'max_shipping_subsidy'    => 0,
        'eligible_customer_types' => ['customer'],
        'excluded_product_codes'  => [],
        'trip_id'                 => $trip->id,
        'is_active'               => true,
    ]);

    $items = collect([(object) [
        'product'    => $prod,
        'quantity'   => 1,
        'product_id' => $prod->id,
    ]]);

    // Warm the cache
    $best = $svc->getBestPromo('customer', $trip->id, $items);
    expect($best['rule']->id)->toBe($rule->id);

    // Clear cache, deactivate rule, next call should return null
    $svc->clearCache();
    $rule->update(['is_active' => false]);

    $bestAfter = $svc->getBestPromo('customer', $trip->id, $items);
    expect($bestAfter)->toBeNull();
});

// ── Fix #15: order_number loop has attempt limit ─────────────────────

test('Order model generates a unique order_number on create', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $area  = $this->shippingArea();
    $cust  = $this->customer($admin);

    $o1 = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $cust->id,
        'created_by' => $admin->id, 'shipping_area_id' => $area->id,
    ]);
    $o2 = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $cust->id,
        'created_by' => $admin->id, 'shipping_area_id' => $area->id,
    ]);

    expect($o1->order_number)->toStartWith('ORD-')
        ->and($o2->order_number)->toStartWith('ORD-')
        ->and($o1->order_number)->not->toBe($o2->order_number);
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