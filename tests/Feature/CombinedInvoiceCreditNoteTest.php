<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;

/*
 * Reported: the combined invoice's Grand Total didn't match the order's
 * own total shown everywhere else (Orders list, Outstanding Balances) once
 * a Credit Note was issued — because this page computes its own Grand
 * Total from scratch (subtotal - discount + shipping - ship discount),
 * independently of the order's stored total_amount, and that formula had
 * no credit-note deduction. Same root bug as two other places already
 * fixed in PromoService; this was the one remaining spot.
 */

test('the combined invoice Grand Total matches the order total_amount once a credit note is active', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $customer->update(['default_shipping_area_id' => null]); // isolate credit-note math from shipping entirely
    $product  = Product::create(['trip_id' => $trip->id, 'product_code' => 'CIBUG01', 'price' => 500000, 'weight_gram' => 100, 'status' => 'active']);
    $order = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'shipping_area_id' => null, 'order_number' => null]);
    OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 500000, 'line_total' => 500000, 'status' => 'pending']);

    $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $order), [
        'type' => 'credit_note', 'amount' => 70000,
    ]);

    $order->refresh();
    expect((float) $order->total_amount)->toBe(430000.0); // sanity: the order's own total is already correct

    $response = $this->actingAs($admin)->get(route('orders.combined-invoice', [
        'customer' => $customer->id, 'trip_id' => $trip->id,
    ]));

    $response->assertOk();
    // The Grand Total shown must equal the order's own total, not the
    // pre-credit-note figure (500,000) the old formula would have shown.
    // (Subtotal legitimately still shows Rp 500.000 on the same page —
    // that's correct and expected, not something to assert against.)
    $response->assertSeeText('Rp 430.000');
    $response->assertSeeText('Credit Note');
});

test('the combined invoice balance due reflects an overpayment correctly after a credit note', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $customer->update(['default_shipping_area_id' => null]); // isolate credit-note math from shipping entirely
    $product  = Product::create(['trip_id' => $trip->id, 'product_code' => 'CIBUG02', 'price' => 500000, 'weight_gram' => 100, 'status' => 'active']);
    $order = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'shipping_area_id' => null, 'order_number' => null]);
    OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 500000, 'line_total' => 500000, 'status' => 'pending']);

    // Pay the full original amount BEFORE the credit note — mirrors the
    // reported scenario where the customer had already paid in full.
    \App\Models\Payment::create([
        'order_id' => $order->id, 'amount' => 500000, 'type' => 'full', 'method' => 'Transfer',
        'paid_at' => now(), 'recorded_by' => $admin->id, 'verification_status' => 'verified',
    ]);
    $order->recalcPaymentStatus();

    $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $order), [
        'type' => 'credit_note', 'amount' => 70000,
    ]);

    // Credit note issues its own refund payment too, so deposit_paid is
    // back down to 430,000 — paid matches the new (lower) total exactly,
    // balance should settle back to 0, not swing negative.
    expect((float) $order->fresh()->deposit_paid)->toBe(430000.0);

    $response = $this->actingAs($admin)->get(route('orders.combined-invoice', [
        'customer' => $customer->id, 'trip_id' => $trip->id,
    ]));
    $response->assertSeeText('Balance Due');
    $response->assertSeeText('Rp 0');
});
