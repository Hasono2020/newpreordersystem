<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesAdjustment;

/*
 * Reported: the Combined Invoice showed every refund with a "+" in green,
 * same as money coming in — and everywhere a refund's type was shown, it
 * just said generic "Refund" rather than Sales Return or Credit Note.
 * invoice.blade.php and the order detail page already had the sign/color
 * right; only the combined invoice needed that fix. The specific label is
 * new everywhere.
 */

test('a refund tied to a Sales Return displays as "Sales Return"', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $product  = Product::create(['trip_id' => $trip->id, 'product_code' => 'DISP01', 'price' => 50000, 'weight_gram' => 100, 'status' => 'active']);
    $order = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'order_number' => null]);
    $item  = OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 2, 'unit_price' => 50000, 'line_total' => 100000, 'status' => 'pending']);

    \App\Models\Payment::create(['order_id' => $order->id, 'amount' => 100000, 'type' => 'deposit', 'method' => 'Transfer', 'paid_at' => now(), 'recorded_by' => $admin->id, 'verification_status' => 'verified']);
    $order->recalcPaymentStatus(); // paid in full, so returning one item leaves a real refund due

    $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $order), [
        'type' => 'return', 'items' => [['order_item_id' => $item->id, 'quantity' => 1]],
    ]);

    $payment = Payment::where('order_id', $order->id)->where('type', 'refund')->first();
    expect($payment->displayType())->toBe('Sales Return');
});

test('a refund tied to a Credit Note displays as "Credit Note"', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $product  = Product::create(['trip_id' => $trip->id, 'product_code' => 'DISP02', 'price' => 50000, 'weight_gram' => 100, 'status' => 'active']);
    $order = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'order_number' => null]);
    OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50000, 'line_total' => 50000, 'status' => 'pending']);

    \App\Models\Payment::create(['order_id' => $order->id, 'amount' => 100000, 'type' => 'deposit', 'method' => 'Transfer', 'paid_at' => now(), 'recorded_by' => $admin->id, 'verification_status' => 'verified']);
    $order->recalcPaymentStatus();

    $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $order), [
        'type' => 'credit_note', 'amount' => 10000,
    ]);

    $payment = Payment::where('order_id', $order->id)->where('type', 'refund')->first();
    expect($payment->displayType())->toBe('Credit Note');
});

test('a manually-recorded refund with no linked adjustment falls back to the plain type label', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $customer = $this->customer($admin);
    $order = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'order_number' => null]);

    $payment = Payment::create([
        'order_id' => $order->id, 'amount' => 20000, 'type' => 'refund', 'method' => 'Transfer',
        'paid_at' => now(), 'recorded_by' => $admin->id, 'verification_status' => 'unverified',
    ]);

    expect($payment->displayType())->toBe('Refund');
});

test('a normal deposit payment still shows its plain type label, unaffected', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $customer = $this->customer($admin);
    $order = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'order_number' => null]);

    $payment = Payment::create([
        'order_id' => $order->id, 'amount' => 50000, 'type' => 'deposit', 'method' => 'Transfer',
        'paid_at' => now(), 'recorded_by' => $admin->id, 'verification_status' => 'unverified',
    ]);

    expect($payment->displayType())->toBe('Deposit');
});

test('the invoice, order detail, and combined invoice pages all render successfully with a Sales-Return-linked refund present', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $product  = Product::create(['trip_id' => $trip->id, 'product_code' => 'DISP03', 'price' => 50000, 'weight_gram' => 100, 'status' => 'active']);
    $order = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'order_number' => null]);
    $item  = OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 2, 'unit_price' => 50000, 'line_total' => 100000, 'status' => 'pending']);

    \App\Models\Payment::create(['order_id' => $order->id, 'amount' => 100000, 'type' => 'deposit', 'method' => 'Transfer', 'paid_at' => now(), 'recorded_by' => $admin->id, 'verification_status' => 'verified']);
    $order->recalcPaymentStatus(); // paid in full, so returning one item leaves a real refund due

    $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $order), [
        'type' => 'return', 'items' => [['order_item_id' => $item->id, 'quantity' => 1]],
    ]);

    $this->actingAs($admin)->get(route('orders.invoice', $order))->assertOk();
    $this->actingAs($admin)->get(route('orders.show', $order))->assertOk();
    $this->actingAs($admin)->get(route('orders.combined-invoice', ['customer' => $customer->id, 'trip_id' => $trip->id]))->assertOk();
});
