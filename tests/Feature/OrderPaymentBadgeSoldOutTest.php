<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;

/*
 * Reported: order ORD/B2/11/007662 (all items sold out, total Rp 0) showed
 * an "Unpaid" badge. Nothing is owed on it, so the badge must say Sold Out.
 */

function badgeOrder($test, array $itemStatuses, float $total): Order
{
    $admin    = $test->adminUser();
    $trip     = $test->openTrip();
    $customer = $test->customer($admin);

    $product = Product::create([
        'trip_id' => $trip->id, 'product_code' => 'TB01',
        'price' => 50000, 'weight_gram' => 100, 'status' => 'active',
    ]);

    $order = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id,
        'created_by' => $admin->id, 'total_amount' => $total,
        'deposit_paid' => 0, 'payment_status' => 'unpaid',
    ]);

    foreach ($itemStatuses as $status) {
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id,
            'quantity' => 1, 'unit_price' => 50000, 'line_total' => 50000,
            'status' => $status,
        ]);
    }

    return $order->fresh();
}

test('all items sold out and total 0 shows Sold Out, not Unpaid', function () {
    $order = badgeOrder($this, ['sold_out', 'sold_out'], 0);

    expect($order->payment_status_badge)->toContain('Sold Out')->not->toContain('Unpaid');
});

test('all items cancelled and total 0 shows Cancelled', function () {
    $order = badgeOrder($this, ['cancelled'], 0);

    expect($order->payment_status_badge)->toContain('Cancelled');
});

test('an order with money owed still shows Unpaid', function () {
    $order = badgeOrder($this, ['pending', 'sold_out'], 50000);

    expect($order->payment_status_badge)->toContain('Unpaid');
});
