<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\PromoRule;
use App\Models\ShippingArea;
use App\Models\Trip;

/*
 * OrderController::show() computes its "promo applied" / "needs N more
 * items" banner separately from the real calculation. The real one
 * (PromoService::recalcCustomerShipping) combines items across ALL of a
 * customer's orders in the trip to decide eligibility - that's how
 * multi-order customers reach thresholds like "30+ items" at all.
 *
 * The banner used to count ONLY $order->items, so a customer who
 * genuinely qualified via their combined orders - and had the promo
 * correctly applied to their real total - could still see "No promo
 * applied, needs N more items" on the order page. Confusing at best,
 * and indistinguishable from an actual pricing bug to whoever's reading it.
 */

test('an order that individually falls short still shows the promo as applied when combined with a sibling order', function () {
    $admin    = $this->adminUser();
    $area     = ShippingArea::factory()->create(['price_per_kg' => 10000]);
    $trip     = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $customer = $this->customer($admin);
    $customer->update(['type' => 'reseller']);

    PromoRule::create([
        'name' => 'Reseller 20+', 'min_items' => 20,
        'discount_per_item' => 1000, 'discount_flat' => 0, 'max_shipping_subsidy' => 0,
        'eligible_customer_types' => ['reseller'], 'excluded_product_codes' => [],
        'trip_id' => $trip->id, 'is_active' => true,
    ]);

    $product = Product::create([
        'trip_id' => $trip->id, 'product_code' => 'CMB_'.fake()->unique()->numerify('####'),
        'price' => 50000, 'weight_gram' => 200, 'status' => 'active',
    ]);

    // 12 + 12 = 24 combined, well over the 20-item threshold, but neither
    // order alone (12) reaches it.
    $order1 = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
        'shipping_area_id' => $area->id, 'subtotal' => 600000, 'total_amount' => 600000,
        'deposit_paid' => 0, 'payment_status' => 'unpaid', 'ordered_at' => now()->subMinutes(5),
    ]);
    OrderItem::create(['order_id' => $order1->id, 'product_id' => $product->id, 'quantity' => 12, 'unit_price' => 50000, 'line_total' => 600000, 'status' => 'pending']);

    $order2 = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
        'shipping_area_id' => $area->id, 'subtotal' => 600000, 'total_amount' => 600000,
        'deposit_paid' => 0, 'payment_status' => 'unpaid', 'ordered_at' => now(),
    ]);
    OrderItem::create(['order_id' => $order2->id, 'product_id' => $product->id, 'quantity' => 12, 'unit_price' => 50000, 'line_total' => 600000, 'status' => 'pending']);

    // Run the real calculation, exactly like editing/creating an order would.
    app(\App\Services\PromoService::class)->recalcCustomerShipping($customer->id, $trip->id);

    // Both orders' pages should show the promo as applied - not just the
    // anchor order that actually carries the discount amount.
    $this->actingAs($admin)->get(route('orders.show', $order1))
        ->assertSee('Promo applied')
        ->assertDontSee('No promo applied');

    $this->actingAs($admin)->get(route('orders.show', $order2))
        ->assertSee('Promo applied')
        ->assertDontSee('No promo applied');
});

test('the "needs N more items" hint counts combined items across the trip, not just the viewed order', function () {
    $admin    = $this->adminUser();
    $area     = ShippingArea::factory()->create(['price_per_kg' => 10000]);
    $trip     = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $customer = $this->customer($admin);
    $customer->update(['type' => 'reseller']);

    PromoRule::create([
        'name' => 'Reseller 30+', 'min_items' => 30,
        'discount_per_item' => 1000, 'discount_flat' => 0, 'max_shipping_subsidy' => 0,
        'eligible_customer_types' => ['reseller'], 'excluded_product_codes' => [],
        'trip_id' => $trip->id, 'is_active' => true,
    ]);

    $product = Product::create([
        'trip_id' => $trip->id, 'product_code' => 'CMB_'.fake()->unique()->numerify('####'),
        'price' => 50000, 'weight_gram' => 200, 'status' => 'active',
    ]);

    // 21 on the viewed order + 5 on a sibling order = 26 combined.
    // Viewing only $order1 used to report "needs 9 more" (30-21) instead
    // of the correct "needs 4 more" (30-26).
    $order1 = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
        'shipping_area_id' => $area->id, 'subtotal' => 1050000, 'total_amount' => 1050000,
        'deposit_paid' => 0, 'payment_status' => 'unpaid', 'ordered_at' => now()->subMinutes(5),
    ]);
    OrderItem::create(['order_id' => $order1->id, 'product_id' => $product->id, 'quantity' => 21, 'unit_price' => 50000, 'line_total' => 1050000, 'status' => 'pending']);

    $order2 = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
        'shipping_area_id' => $area->id, 'subtotal' => 250000, 'total_amount' => 250000,
        'deposit_paid' => 0, 'payment_status' => 'unpaid', 'ordered_at' => now(),
    ]);
    OrderItem::create(['order_id' => $order2->id, 'product_id' => $product->id, 'quantity' => 5, 'unit_price' => 50000, 'line_total' => 250000, 'status' => 'pending']);

    $this->actingAs($admin)->get(route('orders.show', $order1))
        ->assertSee('needs 4 more item')
        ->assertDontSee('needs 9 more item');
});