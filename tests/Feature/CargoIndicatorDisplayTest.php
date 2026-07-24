<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ShippingArea;
use App\Models\Trip;

/*
 * The cargo indicator (shown when a customer's use_cargo flag is on) was
 * built once across three views - the order detail page, the single-order
 * invoice, and the combined invoice - but was later found missing from
 * all three, likely lost when one of those files was independently
 * rewritten elsewhere. Nothing caught that at the time because this was
 * a display-only change with no test coverage.
 *
 * This locks in all three so a future rewrite of any of these views
 * can't silently drop it again without a failing test.
 */

function cargoDisplayOrder($test, Trip $trip, ShippingArea $area, bool $useCargo): Order
{
    $admin    = $test->adminUser();
    $customer = $test->customer($admin);
    $customer->update(['use_cargo' => $useCargo]);

    $product = Product::create(['trip_id' => $trip->id, 'product_code' => 'CD_'.fake()->unique()->numerify('####'), 'price' => 100000, 'weight_gram' => 500, 'status' => 'active']);
    $order = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
        'shipping_area_id' => $area->id, 'subtotal' => 100000, 'total_amount' => 110000,
        'shipping_fee' => 10000, 'shipping_weight_gram' => $useCargo ? 1500 : 500, 'shipping_kg_charged' => $useCargo ? 2 : 1,
    ]);
    OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100000, 'line_total' => 100000, 'status' => 'pending']);

    return $order->fresh(['customer', 'shippingArea']);
}

test('the order detail page shows a cargo badge when the customer uses cargo', function () {
    $admin = $this->adminUser();
    $area  = ShippingArea::factory()->create();
    $trip  = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $order = cargoDisplayOrder($this, $trip, $area, true);

    $this->actingAs($admin)->get(route('orders.show', $order))
        ->assertOk()
        ->assertSee('Cargo (+1kg)');
});

test('the order detail page shows no cargo badge when the customer does not use cargo', function () {
    $admin = $this->adminUser();
    $area  = ShippingArea::factory()->create();
    $trip  = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $order = cargoDisplayOrder($this, $trip, $area, false);

    $this->actingAs($admin)->get(route('orders.show', $order))
        ->assertOk()
        ->assertDontSee('Cargo (+1kg)');
});

test('the single-order invoice notes the cargo bump when the customer uses cargo', function () {
    $admin = $this->adminUser();
    $area  = ShippingArea::factory()->create();
    $trip  = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $order = cargoDisplayOrder($this, $trip, $area, true);

    $this->actingAs($admin)->get(route('orders.invoice', $order))
        ->assertOk()
        ->assertSee('includes cargo');
});

test('the combined invoice shows a Cargo pill and weight note when the customer uses cargo', function () {
    $admin = $this->adminUser();
    $area  = ShippingArea::factory()->create();
    $trip  = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $order = cargoDisplayOrder($this, $trip, $area, true);

    $this->actingAs($admin)
        ->get(route('orders.combined-invoice', ['customer' => $order->customer_id, 'trip_id' => $trip->id]))
        ->assertOk()
        ->assertSee('Cargo')
        ->assertSee('includes cargo');
});

test('the combined invoice shows neither when the customer does not use cargo', function () {
    $admin = $this->adminUser();
    $area  = ShippingArea::factory()->create();
    $trip  = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $order = cargoDisplayOrder($this, $trip, $area, false);

    $this->actingAs($admin)
        ->get(route('orders.combined-invoice', ['customer' => $order->customer_id, 'trip_id' => $trip->id]))
        ->assertOk()
        ->assertDontSee('includes cargo');
});
