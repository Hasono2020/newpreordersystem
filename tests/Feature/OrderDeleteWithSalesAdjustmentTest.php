<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;

/*
 * Reported: deleting an order that had a Sales Return issued against it
 * failed outright with a foreign key constraint error — sales_adjustment_
 * items.order_item_id had no cascade/null behavior, so deleting the
 * order's items (the first step of deleting the order itself) was blocked
 * by the live reference from its own return history. Fixed by making that
 * reference (and product_id / product_variant_id, same latent issue) go
 * null instead of blocking, since the return's own numbers are already
 * stored directly on sales_adjustment_items regardless.
 */

test('an order with an active Sales Return can be deleted (single delete)', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $product  = Product::create(['trip_id' => $trip->id, 'product_code' => 'DELFIX01', 'price' => 50000, 'weight_gram' => 100, 'status' => 'active']);
    $order = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'order_number' => null]);
    $item  = OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 2, 'unit_price' => 50000, 'line_total' => 100000, 'status' => 'pending']);

    $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $order), [
        'type' => 'return', 'items' => [['order_item_id' => $item->id, 'quantity' => 1]],
    ]);

    $response = $this->actingAs($admin)->delete(route('orders.destroy', $order));

    $response->assertRedirect();
    $response->assertSessionDoesntHaveErrors();
    expect(Order::find($order->id))->toBeNull();
});

test('an order with an active Credit Note can be deleted', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $product  = Product::create(['trip_id' => $trip->id, 'product_code' => 'DELFIX02', 'price' => 50000, 'weight_gram' => 100, 'status' => 'active']);
    $order = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'order_number' => null]);
    OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50000, 'line_total' => 50000, 'status' => 'pending']);

    \App\Models\Payment::create(['order_id' => $order->id, 'amount' => 100000, 'type' => 'deposit', 'method' => 'Transfer', 'paid_at' => now(), 'recorded_by' => $admin->id, 'verification_status' => 'verified']);
    $order->recalcPaymentStatus();

    $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $order), [
        'type' => 'credit_note', 'amount' => 10000,
    ]);

    $response = $this->actingAs($admin)->delete(route('orders.destroy', $order));

    $response->assertRedirect();
    expect(Order::find($order->id))->toBeNull();
});

test('bulk-deleting multiple orders, one with Sales Return history, succeeds — this is the exact scenario that failed', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $product  = Product::create(['trip_id' => $trip->id, 'product_code' => 'DELFIX03', 'price' => 50000, 'weight_gram' => 100, 'status' => 'active']);

    $orderA = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'order_number' => null]);
    $itemA  = OrderItem::create(['order_id' => $orderA->id, 'product_id' => $product->id, 'quantity' => 2, 'unit_price' => 50000, 'line_total' => 100000, 'status' => 'pending']);
    $orderB = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'order_number' => null]);
    OrderItem::create(['order_id' => $orderB->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50000, 'line_total' => 50000, 'status' => 'pending']);

    $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $orderA), [
        'type' => 'return', 'items' => [['order_item_id' => $itemA->id, 'quantity' => 1]],
    ]);

    $response = $this->actingAs($admin)->post(route('orders.bulk-destroy'), [
        'order_ids' => [$orderA->id, $orderB->id],
        'action'    => 'selected',
    ]);

    $response->assertRedirect();
    expect(Order::find($orderA->id))->toBeNull();
    expect(Order::find($orderB->id))->toBeNull();
});

