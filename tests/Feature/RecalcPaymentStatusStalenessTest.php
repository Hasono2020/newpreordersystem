<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ShippingArea;
use App\Models\Trip;
use App\Services\PromoService;

/*
 * Root-cause test for the "Partially Paid" status that never updates.
 *
 * recalcCustomerShipping() updates an order's total_amount (via a price
 * sync, a promo becoming newly eligible, a shipping rate change, a
 * customer's cargo flag changing, credit reallocation, etc.) but never
 * re-derived payment_status/deposit_paid afterward. An order that was
 * "Partially Paid" under an old, higher total could become genuinely
 * overpaid once the total dropped, and would keep reading "Partially
 * Paid" indefinitely — because nothing ever re-checked it until a NEW
 * payment was recorded on that specific order.
 *
 * This reproduces that scenario directly: an order with a real payment
 * already recorded, whose total then drops below what was paid via
 * recalcCustomerShipping() (not via PaymentController, so the normal
 * per-payment recalc never fires) — and checks the status flips anyway.
 */

test('an order that becomes overpaid via recalcCustomerShipping flips to paid, not left stale', function () {
    $admin    = $this->adminUser();
    $area     = ShippingArea::factory()->create(['price_per_kg' => 10000, 'flat_fee' => null]);
    $trip     = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $customer = $this->customer($admin);

    $product = Product::create(['trip_id' => $trip->id, 'product_code' => 'RC_'.fake()->unique()->numerify('####'), 'price' => 500000, 'weight_gram' => 500, 'status' => 'active']);
    $order = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
        'shipping_area_id' => $area->id, 'subtotal' => 500000, 'total_amount' => 550000,
        'deposit_paid' => 0, 'payment_status' => 'unpaid',
    ]);
    OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 500000, 'line_total' => 500000, 'status' => 'pending']);

    // A real payment recorded through the normal flow — correctly leaves
    // the order "Partially Paid" against the original 550,000 total
    // (500,000 item + a shipping component not shown in this simplified setup).
    Payment::factory()->create(['order_id' => $order->id, 'amount' => 500000, 'type' => 'deposit', 'paid_at' => now(), 'voided_at' => null]);
    $order->recalcPaymentStatus();
    expect($order->fresh()->payment_status)->toBe('partial'); // 500,000 paid vs 550,000 total

    // Now something changes the price independently of any new payment —
    // this is the actual reported scenario (JASMINE 7911's order dropped
    // from an original total down to Rp340,000 while she'd already paid
    // Rp500,000 against it earlier).
    $product->update(['price' => 300000]);
    $item = $order->items()->first();
    $item->update(['unit_price' => 300000, 'line_total' => 300000]);

    app(PromoService::class)->recalcCustomerShipping($customer->id, $trip->id);

    $fresh = $order->fresh();
    expect((float) $fresh->total_amount)->toBeLessThan(500000.0)
        ->and($fresh->payment_status)->toBe('paid') // was stuck at 'partial' before this fix
        ->and((float) $fresh->deposit_paid)->toBe(500000.0);
});

test('an order that becomes underpaid via recalcCustomerShipping drops from paid to partial', function () {
    $admin    = $this->adminUser();
    $area     = ShippingArea::factory()->create(['price_per_kg' => 10000, 'flat_fee' => null]);
    $trip     = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $customer = $this->customer($admin);

    $product = Product::create(['trip_id' => $trip->id, 'product_code' => 'RC_'.fake()->unique()->numerify('####'), 'price' => 200000, 'weight_gram' => 500, 'status' => 'active']);
    $order = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
        'shipping_area_id' => $area->id, 'subtotal' => 200000, 'total_amount' => 200000,
        'deposit_paid' => 0, 'payment_status' => 'unpaid',
    ]);
    OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 200000, 'line_total' => 200000, 'status' => 'pending']);

    Payment::factory()->create(['order_id' => $order->id, 'amount' => 200000, 'type' => 'deposit', 'paid_at' => now(), 'voided_at' => null]);
    $order->recalcPaymentStatus();
    expect($order->fresh()->payment_status)->toBe('paid');
    expect($order->fresh()->items()->first()->status)->toBe('confirmed'); // auto-confirmed when it became paid

    // Price goes UP — the same 200,000 already paid no longer covers it.
    $product->update(['price' => 500000]);
    $order->items()->first()->update(['unit_price' => 500000, 'line_total' => 500000]);

    app(PromoService::class)->recalcCustomerShipping($customer->id, $trip->id);

    expect($order->fresh()->payment_status)->toBe('partial');
});

test('order-level auto-confirm still fires when recalcCustomerShipping pushes an order to fully paid', function () {
    $admin    = $this->adminUser();
    $area     = ShippingArea::factory()->create(['price_per_kg' => 10000, 'flat_fee' => null]);
    $trip     = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $customer = $this->customer($admin);

    $product = Product::create(['trip_id' => $trip->id, 'product_code' => 'RC_'.fake()->unique()->numerify('####'), 'price' => 500000, 'weight_gram' => 500, 'status' => 'active']);
    $order = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
        'shipping_area_id' => $area->id, 'subtotal' => 500000, 'total_amount' => 500000,
        'deposit_paid' => 0, 'payment_status' => 'unpaid',
    ]);
    $item = OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 500000, 'line_total' => 500000, 'status' => 'pending']);

    Payment::factory()->create(['order_id' => $order->id, 'amount' => 500000, 'type' => 'deposit', 'paid_at' => now(), 'voided_at' => null]);

    $product->update(['price' => 300000]);
    $item->update(['unit_price' => 300000, 'line_total' => 300000]);

    app(PromoService::class)->recalcCustomerShipping($customer->id, $trip->id);

    expect($item->fresh()->status)->toBe('confirmed');
});
