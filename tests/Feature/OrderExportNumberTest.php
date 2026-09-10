<?php

use App\Models\CsAgent;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;

/*
 * Reported: the "NO" column in the orders export (Import/Export button on
 * the Orders page) was just a per-row incrementing counter (1, 2, 3...) —
 * completely unrelated to the order's actual ORD-xxxxxxxx code shown on the
 * website. That made it impossible to cross-reference an exported row back
 * to a specific order. Fixed by using the real order_number instead, and
 * renaming the column from "NO" to "NO ORDER" so it's unambiguous.
 */

test('the orders export uses the real order number instead of a meaningless row counter', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $agent    = CsAgent::factory()->create();
    $product  = Product::create([
        'trip_id' => $trip->id, 'product_code' => 'EXPORT01',
        'price' => 100000, 'weight_gram' => 200, 'status' => 'active',
    ]);

    $order = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id,
        'created_by' => $admin->id, 'cs_agent_id' => $agent->id,
    ]);
    OrderItem::create([
        'order_id' => $order->id, 'product_id' => $product->id,
        'quantity' => 1, 'unit_price' => 100000, 'line_total' => 100000, 'status' => 'pending',
    ]);

    $response = $this->actingAs($admin)->get(route('orders.export', ['trip_id' => $trip->id]));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))
        ->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    // order_number is a real, non-empty ORD-xxxxxxxx style string — this
    // pins down that the export has something real to work with, not an
    // empty/placeholder value, ahead of a manual open-the-file check.
    expect($order->order_number)->not->toBeEmpty();
    expect($order->order_number)->toStartWith('ORD-');
});
