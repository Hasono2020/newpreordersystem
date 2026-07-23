<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ShippingArea;
use App\Models\Supplier;
use App\Models\Trip;

/*
 * Regression guard for a real inconsistency found during review.
 *
 * Order::recalcPaymentStatus() is the single source of truth for payment
 * recalculation, and it auto-confirms pending items once an order becomes
 * fully paid. But the two PRICE SYNC paths (ProductController and
 * ShippingAreaController) each carried their own inlined copy of that
 * calculation which OMITTED the auto-confirm step.
 *
 * Net effect: an order pushed to fully-paid by a price DROP kept its items
 * 'pending', while the identical end state reached by recording a PAYMENT
 * confirmed them — same order, same status, two different item states
 * depending on how it got there.
 *
 * Both call sites now delegate to the model method. These tests fail if
 * anyone re-inlines the logic.
 */

function pscItemOrder($test, Trip $trip, ShippingArea $area, float $price, float $paid): Order
{
    $admin    = $test->adminUser();
    $supplier = Supplier::factory()->create();
    $customer = $test->customer($admin);

    $product = Product::create([
        'trip_id'      => $trip->id,
        'supplier_id'  => $supplier->id,
        'product_code' => 'PSC_' . fake()->unique()->numerify('####'),
        'price'        => $price,
        'weight_gram'  => 500,
        'status'       => 'active',
    ]);

    $order = Order::factory()->create([
        'trip_id'          => $trip->id,
        'customer_id'      => $customer->id,
        'created_by'       => $admin->id,
        'shipping_area_id' => $area->id,
        'subtotal'         => $price,
        'total_amount'     => $price,
        'shipping_fee'     => 0,
        'deposit_paid'     => $paid,
        'payment_status'   => $paid >= $price ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid'),
    ]);

    OrderItem::create([
        'order_id'   => $order->id,
        'product_id' => $product->id,
        'quantity'   => 1,
        'unit_price' => $price,
        'line_total' => $price,
        'status'     => 'pending',
    ]);

    Payment::factory()->create([
        'order_id'  => $order->id,
        'amount'    => $paid,
        'type'      => 'deposit',
        'paid_at'   => now(),
        'voided_at' => null,
    ]);

    return $order->fresh(['items', 'trip']);
}

test('a product price drop that flips an order to fully paid also confirms its pending items', function () {
    $admin = $this->adminUser();
    $trip  = Trip::factory()->open()->create(['created_by' => $admin->id]);
    // price_per_kg 0 keeps shipping out of the arithmetic
    $area  = ShippingArea::factory()->create(['price_per_kg' => 0, 'flat_fee' => null]);

    // Ordered at 100,000, paid 60,000 -> partial, item still pending.
    $order   = pscItemOrder($this, $trip, $area, 100000, 60000);
    $product = $order->items->first()->product;

    expect($order->payment_status)->toBe('partial')
        ->and($order->items->first()->status)->toBe('pending');

    // Drop the price to 50,000 — the 60,000 already paid now covers it.
    $this->actingAs($admin)->put("/products/{$product->id}", [
        'trip_id'      => $trip->id,
        'supplier_id'  => $product->supplier_id,
        'product_code' => $product->product_code,
        'price'        => 50000,
        'weight_gram'  => $product->weight_gram,
        'status'       => $product->status,
    ])->assertRedirect();

    $order->refresh();
    expect($order->payment_status)->toBe('paid')
        ->and($order->items()->first()->status)->toBe('confirmed');
});

test('a shipping rate drop that flips an order to fully paid also confirms its pending items', function () {
    $admin = $this->adminUser();
    $trip  = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $area  = ShippingArea::factory()->create(['price_per_kg' => 40000, 'flat_fee' => null]);

    // Item 100,000 + shipping 40,000 (500g -> 1kg) = 140,000 due; 110,000 paid.
    $order = pscItemOrder($this, $trip, $area, 100000, 110000);
    $order->update(['shipping_fee' => 40000, 'total_amount' => 140000, 'payment_status' => 'partial']);

    expect($order->fresh()->payment_status)->toBe('partial')
        ->and($order->items()->first()->status)->toBe('pending');

    // Drop the rate to 10,000 -> total becomes 110,000, exactly covered.
    $this->actingAs($admin)->put("/shipping/{$area->id}", [
        'name'         => $area->name,
        'province'     => $area->province,
        'pricing_mode' => 'per_kg',
        'price_per_kg' => 10000,
        'is_active'    => 1,
    ])->assertRedirect();

    $order->refresh();
    expect($order->payment_status)->toBe('paid')
        ->and($order->items()->first()->status)->toBe('confirmed');
});