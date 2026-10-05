<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\PromoService;

/*
 * The anchor-selection rule used to live only inside
 * recalcCustomerShipping(). Extracted into its own method so the Payment
 * Log's Credit Note shortcut (and anything else that needs to know "which
 * order is the sensible one for this customer") shares the exact same
 * rule, rather than approximating it a second time and risking the two
 * drifting apart.
 */

test('with a single order, that order is the anchor', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $order = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'order_number' => null]);

    $anchor = app(PromoService::class)->determineAnchorOrder($customer->id, $trip->id);
    expect($anchor->id)->toBe($order->id);
});

test('with multiple orders, the oldest one with active items is the anchor', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $product  = Product::create(['trip_id' => $trip->id, 'product_code' => 'ANCHORU01', 'price' => 50000, 'weight_gram' => 100, 'status' => 'active']);

    $older = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'order_number' => null, 'created_at' => now()->subHour()]);
    OrderItem::create(['order_id' => $older->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50000, 'line_total' => 50000, 'status' => 'pending']);

    $newer = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'order_number' => null]);
    OrderItem::create(['order_id' => $newer->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50000, 'line_total' => 50000, 'status' => 'pending']);

    $anchor = app(PromoService::class)->determineAnchorOrder($customer->id, $trip->id);
    expect($anchor->id)->toBe($older->id); // oldest wins when both have active items
});

test('an order whose items are all fully returned (quantity 0) is skipped in favor of the next order with active items', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $product  = Product::create(['trip_id' => $trip->id, 'product_code' => 'ANCHORU02', 'price' => 50000, 'weight_gram' => 100, 'status' => 'active']);

    $older = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'order_number' => null, 'created_at' => now()->subHour()]);
    OrderItem::create(['order_id' => $older->id, 'product_id' => $product->id, 'quantity' => 0, 'unit_price' => 50000, 'line_total' => 0, 'status' => 'pending']);

    $newer = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'order_number' => null]);
    OrderItem::create(['order_id' => $newer->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50000, 'line_total' => 50000, 'status' => 'pending']);

    $anchor = app(PromoService::class)->determineAnchorOrder($customer->id, $trip->id);
    expect($anchor->id)->toBe($newer->id);
});

test('if every order has zero active items, it falls back to the oldest order anyway rather than returning nothing', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $product  = Product::create(['trip_id' => $trip->id, 'product_code' => 'ANCHORU03', 'price' => 50000, 'weight_gram' => 100, 'status' => 'active']);

    $older = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'order_number' => null, 'created_at' => now()->subHour()]);
    OrderItem::create(['order_id' => $older->id, 'product_id' => $product->id, 'quantity' => 0, 'unit_price' => 50000, 'line_total' => 0, 'status' => 'pending']);

    $newer = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'order_number' => null]);
    OrderItem::create(['order_id' => $newer->id, 'product_id' => $product->id, 'quantity' => 0, 'unit_price' => 50000, 'line_total' => 0, 'status' => 'pending']);

    $anchor = app(PromoService::class)->determineAnchorOrder($customer->id, $trip->id);
    expect($anchor->id)->toBe($older->id);
});

test('returns null for a customer with no orders in the trip', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);

    $anchor = app(PromoService::class)->determineAnchorOrder($customer->id, $trip->id);
    expect($anchor)->toBeNull();
});
