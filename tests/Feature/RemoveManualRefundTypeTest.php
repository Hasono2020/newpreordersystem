<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;

/*
 * The manual "Record Payment" panel and the Credit Note flow could both
 * create a type=refund payment, but only Credit Note gave it a real,
 * numbered, trackable record. Removed 'refund' as an option from manual
 * Record Payment (UI dropdown + server-side validation) so Credit Note is
 * the one way to do this going forward. Sales Return / Credit Note create
 * their refund payments through a separate code path (SalesAdjustmentController),
 * not through addPayment(), so they're unaffected by this change.
 */

test('manually recording a type=refund payment through Record Payment is now rejected', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $customer = $this->customer($admin);
    $order = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'order_number' => null]);

    $response = $this->actingAs($admin)->post(route('orders.payments.add', $order), [
        'amount' => 50000, 'type' => 'refund', 'paid_at' => now()->format('Y-m-d'),
    ]);

    $response->assertSessionHasErrors('type');
    expect(Payment::where('order_id', $order->id)->count())->toBe(0);
});

test('the normal payment types (deposit, partial, full) still work through Record Payment', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $customer = $this->customer($admin);
    $order = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'order_number' => null]);

    $response = $this->actingAs($admin)->post(route('orders.payments.add', $order), [
        'amount' => 50000, 'type' => 'deposit', 'paid_at' => now()->format('Y-m-d'),
    ]);

    $response->assertSessionDoesntHaveErrors();
    expect(Payment::where('order_id', $order->id)->where('type', 'deposit')->count())->toBe(1);
});

test('Credit Note still creates its own refund payment correctly — unaffected by removing manual refund', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $product  = Product::create(['trip_id' => $trip->id, 'product_code' => 'NOREFUND01', 'price' => 50000, 'weight_gram' => 100, 'status' => 'active']);
    $order = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'order_number' => null]);
    OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50000, 'line_total' => 50000, 'status' => 'pending']);

    \App\Models\Payment::create(['order_id' => $order->id, 'amount' => 100000, 'type' => 'deposit', 'method' => 'Transfer', 'paid_at' => now(), 'recorded_by' => $admin->id, 'verification_status' => 'verified']);
    $order->recalcPaymentStatus();

    $response = $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $order), [
        'type' => 'credit_note', 'amount' => 10000,
    ]);

    $response->assertSessionDoesntHaveErrors();
    expect(Payment::where('order_id', $order->id)->where('type', 'refund')->count())->toBe(1);
});
