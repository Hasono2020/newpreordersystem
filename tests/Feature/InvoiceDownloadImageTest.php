<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ShippingArea;
use App\Models\Trip;

/*
 * The actual image generation (html2canvas rendering the invoice DOM to a
 * canvas, then triggering a download) is pure client-side JavaScript —
 * Pest has no headless browser here to actually exercise it, the same
 * limitation already noted for the flat-fee shipping calculator earlier
 * in this codebase's history. These tests only confirm the button and
 * the library are present on the page, which is enough to catch a future
 * rewrite silently dropping it again (as already happened once with the
 * cargo indicator on these same two views).
 */

test('the single-order invoice has a Download as Image button and loads html2canvas', function () {
    $admin = $this->adminUser();
    $area  = ShippingArea::factory()->create();
    $trip  = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $customer = $this->customer($admin);
    $product  = Product::create(['trip_id' => $trip->id, 'product_code' => 'IMG_'.fake()->unique()->numerify('####'), 'price' => 100000, 'weight_gram' => 500, 'status' => 'active']);
    $order = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id, 'shipping_area_id' => $area->id]);
    OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100000, 'line_total' => 100000, 'status' => 'pending']);

    $this->actingAs($admin)->get(route('orders.invoice', $order))
        ->assertOk()
        ->assertSee('downloadImageBtn', false)
        ->assertSee('html2canvas', false);
});

test('the combined invoice has a Download as Image button and loads html2canvas', function () {
    $admin = $this->adminUser();
    $area  = ShippingArea::factory()->create();
    $trip  = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $customer = $this->customer($admin);
    $product  = Product::create(['trip_id' => $trip->id, 'product_code' => 'IMG_'.fake()->unique()->numerify('####'), 'price' => 100000, 'weight_gram' => 500, 'status' => 'active']);
    $order = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id, 'shipping_area_id' => $area->id]);
    OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100000, 'line_total' => 100000, 'status' => 'pending']);

    $this->actingAs($admin)
        ->get(route('orders.combined-invoice', ['customer' => $customer->id, 'trip_id' => $trip->id]))
        ->assertOk()
        ->assertSee('downloadImageBtn', false)
        ->assertSee('html2canvas', false);
});
