<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ShippingArea;
use App\Models\Trip;
use App\Services\PromoService;

/*
 * Tests for the customer-level "use cargo" flag: when enabled, +1000g is
 * added to the chargeable shipping weight, once per shipment — not once
 * per order, even when a customer's orders combine into one shipment.
 */

test('calcTotalWeightGram adds 1000g when cargo is enabled and there is real weight', function () {
    $trip     = Trip::factory()->open()->create(['created_by' => $this->adminUser()->id]);
    $product  = Product::create(['trip_id' => $trip->id, 'product_code' => 'CG_'.fake()->unique()->numerify('####'), 'price' => 10000, 'weight_gram' => 500, 'status' => 'active']);
    $order    = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $this->customer()->id]);
    $item     = OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 10000, 'line_total' => 10000, 'status' => 'pending']);

    $svc   = app(PromoService::class);
    $items = collect([$item->fresh('product')]);

    expect($svc->calcTotalWeightGram($items, false))->toBe(500)
        ->and($svc->calcTotalWeightGram($items, true))->toBe(1500);
});

test('calcTotalWeightGram does not add the bump when there is nothing to ship', function () {
    $svc = app(PromoService::class);
    expect($svc->calcTotalWeightGram(collect(), true))->toBe(0);
});

test('a single order for a cargo customer gets the weight bump via recalculate()', function () {
    $admin    = $this->adminUser();
    $area     = ShippingArea::factory()->create(['price_per_kg' => 10000, 'flat_fee' => null]);
    $trip     = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $customer = $this->customer($admin);
    $customer->update(['use_cargo' => true]);

    $product = Product::create(['trip_id' => $trip->id, 'product_code' => 'CG_'.fake()->unique()->numerify('####'), 'price' => 50000, 'weight_gram' => 500, 'status' => 'active']);
    $order = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
        'shipping_area_id' => $area->id, 'subtotal' => 0, 'total_amount' => 0,
    ]);
    OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50000, 'line_total' => 50000, 'status' => 'pending']);

    // 500g + 1000g cargo = 1500g -> chargeable 2kg (>1320g) -> 20,000 shipping
    $calc = app(PromoService::class)->recalculate($order->fresh(['items.product', 'customer', 'shippingArea']));

    expect($calc['shipping_weight_gram'])->toBe(1500)
        ->and($calc['shipping_kg_charged'])->toBe(2.0)
        ->and($calc['shipping_fee'])->toBe(20000.0);
});

test('a non-cargo customer with the same order is not bumped', function () {
    $admin    = $this->adminUser();
    $area     = ShippingArea::factory()->create(['price_per_kg' => 10000, 'flat_fee' => null]);
    $trip     = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $customer = $this->customer($admin); // use_cargo defaults to false

    $product = Product::create(['trip_id' => $trip->id, 'product_code' => 'CG_'.fake()->unique()->numerify('####'), 'price' => 50000, 'weight_gram' => 500, 'status' => 'active']);
    $order = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
        'shipping_area_id' => $area->id, 'subtotal' => 0, 'total_amount' => 0,
    ]);
    OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50000, 'line_total' => 50000, 'status' => 'pending']);

    $calc = app(PromoService::class)->recalculate($order->fresh(['items.product', 'customer', 'shippingArea']));

    // 500g, no bump -> still 1kg tier -> 10,000 shipping
    expect($calc['shipping_weight_gram'])->toBe(500)
        ->and($calc['shipping_fee'])->toBe(10000.0);
});

test('the cargo bump applies once for a combined shipment, not once per order', function () {
    $admin    = $this->adminUser();
    $area     = ShippingArea::factory()->create(['price_per_kg' => 10000, 'flat_fee' => null]);
    $trip     = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $customer = $this->customer($admin);
    $customer->update(['use_cargo' => true]);

    $product = Product::create(['trip_id' => $trip->id, 'product_code' => 'CG_'.fake()->unique()->numerify('####'), 'price' => 50000, 'weight_gram' => 400, 'status' => 'active']);

    // Two orders, 400g each -> 800g combined active-item weight.
    $order1 = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id, 'shipping_area_id' => $area->id, 'ordered_at' => now()->subMinutes(5)]);
    OrderItem::create(['order_id' => $order1->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50000, 'line_total' => 50000, 'status' => 'pending']);

    $order2 = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id, 'shipping_area_id' => $area->id, 'ordered_at' => now()]);
    OrderItem::create(['order_id' => $order2->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50000, 'line_total' => 50000, 'status' => 'pending']);

    app(PromoService::class)->recalcCustomerShipping($customer->id, $trip->id);

    // 800g combined + a SINGLE 1000g cargo bump = 1800g -> chargeable 2kg
    // (not 400+1000 + 400+1000 = 2800g -> 3kg, which is what "bump per
    // order" would have produced).
    $order1->refresh();
    expect((float) $order1->shipping_weight_gram)->toBe(1800.0)
        ->and((float) $order1->shipping_fee)->toBe(20000.0)
        ->and((float) $order2->fresh()->shipping_fee)->toBe(0.0); // non-anchor carries no shipping
});

test('CustomerController store saves use_cargo', function () {
    $admin = $this->adminUser();
    $area  = ShippingArea::factory()->create();

    $this->actingAs($admin)->post(route('customers.store'), [
        'name' => 'Cargo Customer', 'phone' => '081234500011', 'type' => 'customer',
        'default_shipping_area_id' => $area->id, 'use_cargo' => '1',
    ])->assertRedirect();

    expect(Customer::where('phone', '081234500011')->first()->use_cargo)->toBeTrue();
});

test('CustomerController update recalculates all trips when use_cargo changes', function () {
    $admin    = $this->adminUser();
    $area     = ShippingArea::factory()->create(['price_per_kg' => 10000, 'flat_fee' => null]);
    $trip     = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $customer = $this->customer($admin);
    $customer->update(['default_shipping_area_id' => $area->id]);

    $product = Product::create(['trip_id' => $trip->id, 'product_code' => 'CG_'.fake()->unique()->numerify('####'), 'price' => 50000, 'weight_gram' => 500, 'status' => 'active']);
    $order = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id, 'shipping_area_id' => $area->id]);
    OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50000, 'line_total' => 50000, 'status' => 'pending']);
    app(PromoService::class)->recalcCustomerShipping($customer->id, $trip->id);
    expect((float) $order->fresh()->shipping_fee)->toBe(10000.0); // baseline, no cargo yet

    $this->actingAs($admin)->put(route('customers.update', $customer), [
        'name' => $customer->name, 'phone' => $customer->phone, 'type' => $customer->type,
        'default_shipping_area_id' => $area->id, 'use_cargo' => '1',
    ])->assertSessionHas('success');

    expect((float) $order->fresh()->shipping_fee)->toBe(20000.0); // bumped after enabling cargo
});

test('CustomerController update does not force a recalc when use_cargo is unchanged', function () {
    $admin    = $this->adminUser();
    $area     = ShippingArea::factory()->create();
    $customer = $this->customer($admin);
    $customer->update(['default_shipping_area_id' => $area->id, 'use_cargo' => false]);

    $response = $this->actingAs($admin)->put(route('customers.update', $customer), [
        'name' => 'Renamed Only', 'phone' => $customer->phone, 'type' => $customer->type,
        'default_shipping_area_id' => $area->id, 'use_cargo' => '0',
    ]);

    $response->assertSessionHas('success', 'Customer updated.');
});
