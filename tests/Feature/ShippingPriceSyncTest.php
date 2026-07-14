<?php

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ShippingArea;
use App\Models\Trip;

/*
 * Tests for the automatic shipping price sync: changing a ShippingArea's
 * price_per_kg immediately recalculates shipping_fee (and total_amount)
 * for every order using that area whose TRIP is still 'open' — regardless
 * of the order's own payment status. This is a deliberate product choice:
 * unlike a payment-status guard, the boundary here is "has the trip
 * shipped yet", not "has this specific order been paid yet". So an
 * already fully-paid order in an open trip CAN and WILL be flipped back
 * to 'partial' if the new fee pushes its total above what was paid —
 * that's expected/intended behavior, not a bug, and is explicitly
 * covered below rather than guarded against.
 */

// ── Local builders ───────────────────────────────────────────────────

/**
 * @param mixed $test Pest binds $this to a TestCase subclass at runtime;
 *        typed as mixed here because static analyzers see Pest's
 *        closure-bound $this as Pest\PendingCalls\TestCall, which isn't
 *        assignable to a concrete TestCase type hint.
 */
function syncTestOrder($test, ShippingArea $area, Trip $trip, int $grams = 1000, int $unitPrice = 100000): Order
{
    $customer = $test->customer();
    $product  = Product::create([
        'trip_id'      => $trip->id,
        'product_code' => 'SY_' . fake()->unique()->numerify('###'),
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

function recordRealPayment(Order $order, float $amount): Payment
{
    $payment = Payment::factory()->create([
        'order_id'    => $order->id,
        'amount'      => $amount,
        'type'        => 'deposit',
        'paid_at'     => now(),
        'voided_at'   => null,
    ]);
    // Reflect the payment on the order the same way PaymentController
    // would, so the "before" state is realistic.
    $order->update([
        'deposit_paid'   => $amount,
        'payment_status' => $amount >= $order->total_amount ? 'paid' : ($amount > 0 ? 'partial' : 'unpaid'),
    ]);
    return $payment;
}

// ── Tests ─────────────────────────────────────────────────────────────

test('changing price_per_kg recalculates shipping_fee for orders in open trips', function () {
    $admin = $this->adminUser();
    $area  = ShippingArea::factory()->create(['price_per_kg' => 25000]);
    $trip  = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $order = syncTestOrder($this, $area, $trip, 1000); // 1000g -> 1kg chargeable

    $this->actingAs($admin)->put(route('shipping.update', $area), [
        'name'         => $area->name,
        'province'     => $area->province,
        'price_per_kg' => 30000,
        'is_active'    => 1,
    ])->assertRedirect();

    $fresh = $order->fresh();
    expect((float) $fresh->shipping_fee)->toBe(30000.0)
        ->and((float) $fresh->total_amount)->toBe(130000.0); // 100000 subtotal + 30000 shipping
});

test('orders in a non-open trip are not recalculated', function () {
    $admin = $this->adminUser();
    $area  = ShippingArea::factory()->create(['price_per_kg' => 25000]);
    $trip  = Trip::factory()->closed()->create(['created_by' => $admin->id]); // status: order_closed
    $order = syncTestOrder($this, $area, $trip, 1000);
    $originalFee = $order->shipping_fee;

    $this->actingAs($admin)->put(route('shipping.update', $area), [
        'name'         => $area->name,
        'province'     => $area->province,
        'price_per_kg' => 30000,
        'is_active'    => 1,
    ]);

    expect((float) $order->fresh()->shipping_fee)->toBe((float) $originalFee);
});

test('orders using a different shipping area are untouched', function () {
    $admin       = $this->adminUser();
    $targetArea  = ShippingArea::factory()->create(['price_per_kg' => 25000]);
    $otherArea   = ShippingArea::factory()->create(['price_per_kg' => 40000]);
    $trip        = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $otherOrder  = syncTestOrder($this, $otherArea, $trip, 1000);
    $originalFee = $otherOrder->shipping_fee;

    $this->actingAs($admin)->put(route('shipping.update', $targetArea), [
        'name'         => $targetArea->name,
        'province'     => $targetArea->province,
        'price_per_kg' => 30000,
        'is_active'    => 1,
    ]);

    expect((float) $otherOrder->fresh()->shipping_fee)->toBe((float) $originalFee);
});

test('saving without changing price_per_kg does not trigger a sync', function () {
    $admin = $this->adminUser();
    $area  = ShippingArea::factory()->create(['price_per_kg' => 25000, 'name' => 'Surabaya']);
    $trip  = Trip::factory()->open()->create(['created_by' => $admin->id]);
    syncTestOrder($this, $area, $trip, 1000);

    $this->actingAs($admin)->put(route('shipping.update', $area), [
        'name'         => 'Surabaya Updated', // change something else, not the rate
        'province'     => $area->province,
        'price_per_kg' => 25000, // unchanged
        'is_active'    => 1,
    ]);

    expect(ActivityLog::where('action', 'shipping.price_synced')->exists())->toBeFalse();
});

test('an already fully-paid order in an open trip is flipped back to partial when the fee increases', function () {
    $admin = $this->adminUser();
    $area  = ShippingArea::factory()->create(['price_per_kg' => 25000]);
    $trip  = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $order = syncTestOrder($this, $area, $trip, 1000, 100000);

    // Simulate the order having already been fully paid at the OLD rate:
    // subtotal 100000 + shipping 25000 = 125000 total, fully covered.
    $order->update(['shipping_fee' => 25000, 'total_amount' => 125000]);
    recordRealPayment($order, 125000);
    expect($order->fresh()->payment_status)->toBe('paid');

    // Rate goes up — this is the deliberate, accepted behavior: paid
    // orders in open trips are NOT protected from this.
    $this->actingAs($admin)->put(route('shipping.update', $area), [
        'name'         => $area->name,
        'province'     => $area->province,
        'price_per_kg' => 30000,
        'is_active'    => 1,
    ]);

    $fresh = $order->fresh();
    expect((float) $fresh->total_amount)->toBe(130000.0)
        ->and($fresh->payment_status)->toBe('partial')
        ->and((float) $fresh->deposit_paid)->toBe(125000.0);
});

test('a fully-paid order stays paid if the new fee still fits within what was already paid', function () {
    $admin = $this->adminUser();
    $area  = ShippingArea::factory()->create(['price_per_kg' => 25000]);
    $trip  = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $order = syncTestOrder($this, $area, $trip, 1000, 100000);

    $order->update(['shipping_fee' => 25000, 'total_amount' => 125000]);
    recordRealPayment($order, 125000);

    // Rate goes DOWN — new total (100000 + 20000 = 120000) is still fully
    // covered by the 125000 already paid.
    $this->actingAs($admin)->put(route('shipping.update', $area), [
        'name'         => $area->name,
        'province'     => $area->province,
        'price_per_kg' => 20000,
        'is_active'    => 1,
    ]);

    $fresh = $order->fresh();
    expect((float) $fresh->total_amount)->toBe(120000.0)
        ->and($fresh->payment_status)->toBe('paid');
});

test('rate change writes a shipping.price_synced activity log entry', function () {
    $admin = $this->adminUser();
    $area  = ShippingArea::factory()->create(['price_per_kg' => 25000, 'name' => 'Surabaya']);
    $trip  = Trip::factory()->open()->create(['created_by' => $admin->id]);
    syncTestOrder($this, $area, $trip, 1000);

    $this->actingAs($admin)->put(route('shipping.update', $area), [
        'name'         => $area->name,
        'province'     => $area->province,
        'price_per_kg' => 30000,
        'is_active'    => 1,
    ]);

    expect(ActivityLog::where('action', 'shipping.price_synced')
        ->where('subject_type', 'shipping_area')
        ->where('subject_id', $area->id)
        ->exists())->toBeTrue();
});

test('multiple orders for the same customer and trip are combined, not double-charged', function () {
    $admin    = $this->adminUser();
    $area     = ShippingArea::factory()->create(['price_per_kg' => 25000]);
    $trip     = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $customer = $this->customer();

    $product1 = Product::create([
        'trip_id' => $trip->id, 'product_code' => 'SY_'.fake()->unique()->numerify('###'),
        'price' => 100000, 'weight_gram' => 500, 'status' => 'active',
    ]);
    $product2 = Product::create([
        'trip_id' => $trip->id, 'product_code' => 'SY_'.fake()->unique()->numerify('###'),
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