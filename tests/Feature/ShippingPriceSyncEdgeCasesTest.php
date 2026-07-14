<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ShippingArea;
use App\Models\Trip;

/*
 * Supplements tests/Feature/PriceSyncTest.php, which already comprehensively
 * covers the shipping price sync feature (open-trip recalculation, non-open
 * trip exclusion, paid→partial downgrade, activity log, no-op when
 * unchanged). This file only adds the two scenarios not already covered
 * there: that a rate change doesn't leak into orders using a *different*
 * shipping area, and that combined shipping across a customer's multiple
 * orders in one trip is still charged once, not per-order, after a sync.
 */

/**
 * @param mixed $test Pest binds $this to a TestCase subclass at runtime;
 *        typed as mixed here because static analyzers see Pest's
 *        closure-bound $this as Pest\PendingCalls\TestCall, which isn't
 *        assignable to a concrete TestCase type hint.
 */
function shipEdgeOrder($test, ShippingArea $area, Trip $trip, int $grams = 1000, int $unitPrice = 100000): Order
{
    $customer = $test->customer();
    $product  = Product::create([
        'trip_id'      => $trip->id,
        'product_code' => 'SE_' . fake()->unique()->numerify('###'),
        'price'        => $unitPrice,
        'weight_gram'  => $grams,
        'status'       => 'active',
    ]);

    $order = Order::factory()->create([
        'trip_id'          => $trip->id,
        'customer_id'      => $customer->id,
        'shipping_area_id' => $area->id,
        'subtotal'         => $unitPrice,
        'total_amount'     => $unitPrice,
        'shipping_fee'     => 0,
        'payment_status'   => 'unpaid',
        'deposit_paid'     => 0,
    ]);

    OrderItem::create([
        'order_id'   => $order->id,
        'product_id' => $product->id,
        'quantity'   => 1,
        'unit_price' => $unitPrice,
        'line_total' => $unitPrice,
        'status'     => 'pending',
    ]);

    return $order->fresh(['items.product', 'customer', 'shippingArea', 'trip']);
}

test('a rate change on one shipping area does not affect orders using a different area', function () {
    $admin       = $this->adminUser();
    $targetArea  = ShippingArea::factory()->create(['price_per_kg' => 25000]);
    $otherArea   = ShippingArea::factory()->create(['price_per_kg' => 40000]);
    $trip        = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $otherOrder  = shipEdgeOrder($this, $otherArea, $trip, 1000);
    $originalFee = $otherOrder->shipping_fee;

    $this->actingAs($admin)->put(route('shipping.update', $targetArea), [
        'name'         => $targetArea->name,
        'province'     => $targetArea->province,
        'price_per_kg' => 30000,
        'is_active'    => 1,
    ]);

    expect((float) $otherOrder->fresh()->shipping_fee)->toBe((float) $originalFee);
});

test('multiple orders for the same customer and trip are combined, not double-charged, after a sync', function () {
    $admin    = $this->adminUser();
    $area     = ShippingArea::factory()->create(['price_per_kg' => 25000]);
    $trip     = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $customer = $this->customer();

    $product1 = Product::create([
        'trip_id' => $trip->id, 'product_code' => 'SE_'.fake()->unique()->numerify('###'),
        'price' => 100000, 'weight_gram' => 500, 'status' => 'active',
    ]);
    $product2 = Product::create([
        'trip_id' => $trip->id, 'product_code' => 'SE_'.fake()->unique()->numerify('###'),
        'price' => 50000, 'weight_gram' => 500, 'status' => 'active',
    ]);

    $order1 = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'shipping_area_id' => $area->id,
        'subtotal' => 100000, 'total_amount' => 100000, 'shipping_fee' => 0,
        'payment_status' => 'unpaid', 'deposit_paid' => 0,
        'ordered_at' => now()->subMinutes(10),
    ]);
    OrderItem::create(['order_id' => $order1->id, 'product_id' => $product1->id, 'quantity' => 1, 'unit_price' => 100000, 'line_total' => 100000, 'status' => 'pending']);

    $order2 = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'shipping_area_id' => $area->id,
        'subtotal' => 50000, 'total_amount' => 50000, 'shipping_fee' => 0,
        'payment_status' => 'unpaid', 'deposit_paid' => 0,
        'ordered_at' => now(),
    ]);
    OrderItem::create(['order_id' => $order2->id, 'product_id' => $product2->id, 'quantity' => 1, 'unit_price' => 50000, 'line_total' => 50000, 'status' => 'pending']);

    $this->actingAs($admin)->put(route('shipping.update', $area), [
        'name' => $area->name, 'province' => $area->province,
        'price_per_kg' => 30000, 'is_active' => 1,
    ]);

    // Combined weight 500+500=1000g -> still 1kg chargeable -> ONE shipping
    // fee of 30000, charged once on the anchor (oldest order), not twice.
    expect((float) $order1->fresh()->shipping_fee)->toBe(30000.0)
        ->and((float) $order2->fresh()->shipping_fee)->toBe(0.0);
});