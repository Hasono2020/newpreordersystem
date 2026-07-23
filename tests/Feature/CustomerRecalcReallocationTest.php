<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ShippingArea;
use App\Models\Trip;

/*
 * CreditReallocationService was only ever triggered from a product price
 * sync or a shipping rate sync. CustomerController's own recalc paths
 * (changing a customer's type/area/cargo flag, and the "apply shipping
 * to all orders" action) called recalcCustomerShipping() the same way,
 * but never followed up with reallocation — so an overpay/underpay split
 * created or exposed by one of THOSE actions could sit stranded
 * indefinitely unless someone ran `payments:reallocate-credit` by hand.
 *
 * This is the exact scenario reported: JASMINE 7911 had one order
 * overpaid by Rp160,000 and another short by exactly Rp160,000 in the
 * same trip, which kept her out of Ready to Pack since that requires
 * EVERY order to individually be payment_status='paid'.
 */

/**
 * @param mixed $test Pest binds $this to a TestCase subclass at runtime;
 *        typed as mixed here because static analyzers see Pest's
 *        closure-bound $this as Pest\PendingCalls\TestCall, which isn't
 *        assignable to a concrete TestCase type hint.
 */
function creditGapOrder($test, Trip $trip, ShippingArea $area, Customer $customer, float $total, float $paid): Order
{
    $admin   = $test->adminUser();
    $product = \App\Models\Product::create([
        'trip_id' => $trip->id, 'product_code' => 'CG_'.fake()->unique()->numerify('####'),
        'price' => $total, 'weight_gram' => 0, 'status' => 'active',
    ]);
    $order = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
        'shipping_area_id' => $area->id, 'subtotal' => $total, 'total_amount' => $total,
        'deposit_paid' => $paid, 'payment_status' => $paid >= $total ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid'),
    ]);
    // recalcCustomerShipping() (triggered by the actions under test)
    // recomputes subtotal from the order's ACTUAL items — with no item
    // attached, subtotal collapses to 0 and both orders in a test would
    // trivially read "paid" regardless of whether reallocation actually
    // ran. This item keeps the total stable at what the test intends.
    \App\Models\OrderItem::create([
        'order_id' => $order->id, 'product_id' => $product->id,
        'quantity' => 1, 'unit_price' => $total, 'line_total' => $total, 'status' => 'pending',
    ]);
    if ($paid > 0) {
        Payment::factory()->create(['order_id' => $order->id, 'amount' => $paid, 'type' => 'deposit', 'paid_at' => now(), 'voided_at' => null]);
    }
    return $order;
}

test('changing a customer\'s type reallocates stranded credit across their orders', function () {
    $admin    = $this->adminUser();
    $area     = ShippingArea::factory()->create();
    $trip     = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $customer = $this->customer($admin);
    $customer->update(['type' => 'customer', 'default_shipping_area_id' => $area->id]);

    $overpaid  = creditGapOrder($this, $trip, $area, $customer, 340000, 500000); // +160,000 credit
    $shortfall = creditGapOrder($this, $trip, $area, $customer, 500000, 340000); // -160,000 short

    $this->actingAs($admin)->put(route('customers.update', $customer), [
        'name' => $customer->name, 'phone' => $customer->phone,
        'type' => 'reseller', // changed, triggers the recalc path
        'default_shipping_area_id' => $area->id, 'use_cargo' => '0',
    ])->assertRedirect();

    expect($overpaid->fresh()->payment_status)->toBe('paid')
        ->and($shortfall->fresh()->payment_status)->toBe('paid')
        ->and((float) $shortfall->fresh()->deposit_paid)->toBe(500000.0);
});

test('changing a customer\'s cargo flag also reallocates stranded credit', function () {
    $admin    = $this->adminUser();
    $area     = ShippingArea::factory()->create();
    $trip     = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $customer = $this->customer($admin);
    $customer->update(['type' => 'customer', 'default_shipping_area_id' => $area->id, 'use_cargo' => false]);

    creditGapOrder($this, $trip, $area, $customer, 340000, 500000);
    $shortfall = creditGapOrder($this, $trip, $area, $customer, 500000, 340000);

    $this->actingAs($admin)->put(route('customers.update', $customer), [
        'name' => $customer->name, 'phone' => $customer->phone, 'type' => $customer->type,
        'default_shipping_area_id' => $area->id, 'use_cargo' => '1', // changed
    ])->assertRedirect();

    expect($shortfall->fresh()->payment_status)->toBe('paid');
});

test('applyShipping also reallocates stranded credit after re-applying the area', function () {
    $admin    = $this->adminUser();
    $area     = ShippingArea::factory()->create();
    $trip     = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $customer = $this->customer($admin);
    $customer->update(['default_shipping_area_id' => $area->id]);

    creditGapOrder($this, $trip, $area, $customer, 340000, 500000);
    $shortfall = creditGapOrder($this, $trip, $area, $customer, 500000, 340000);

    $this->actingAs($admin)->post(route('customers.apply-shipping', $customer))->assertRedirect();

    expect($shortfall->fresh()->payment_status)->toBe('paid');
});

test('no changes means no reallocation runs (nothing to fix, nothing touched)', function () {
    $admin    = $this->adminUser();
    $area     = ShippingArea::factory()->create();
    $trip     = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $customer = $this->customer($admin);
    $customer->update(['type' => 'customer', 'default_shipping_area_id' => $area->id]);

    $order = creditGapOrder($this, $trip, $area, $customer, 500000, 200000);

    $this->actingAs($admin)->put(route('customers.update', $customer), [
        'name' => 'Renamed Only', 'phone' => $customer->phone, 'type' => $customer->type,
        'default_shipping_area_id' => $area->id, 'use_cargo' => '0',
    ]);

    // Unchanged — still genuinely partial, nothing stranded to move.
    expect($order->fresh()->payment_status)->toBe('partial');
});