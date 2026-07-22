<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\PromoRule;
use App\Models\ShippingArea;
use App\Models\Trip;
use App\Models\User;
use App\Services\PromoService;

/*
 * Fix #4 — recalcCustomerShipping() zeros all financial fields when every
 *           item in every order is cancelled/sold_out, instead of applying
 *           a discount to an anchor with subtotal = 0.
 *
 * Fix #5 (promo best-pick) — getBestPromo() prefers real benefit (discount +
 *           min(subsidy, actualShippingFee)) over theoretical max subsidy.
 *
 * Also covers the flat-fee promo interaction (shipping = flat, subsidy capped).
 */

function makeTripWithArea(int $pricePerKg = 20000): array
{
    $admin = User::factory()->admin()->create();
    $trip  = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $area  = ShippingArea::factory()->create(['price_per_kg' => $pricePerKg]);
    $cust  = Customer::factory()->create([
        'type' => 'customer', 'default_shipping_area_id' => $area->id, 'created_by' => $admin->id,
    ]);
    return [$admin, $trip, $area, $cust];
}

function makeItem(Order $order, Product $product, string $status = 'pending'): OrderItem
{
    return OrderItem::create([
        'order_id'   => $order->id,
        'product_id' => $product->id,
        'quantity'   => 1,
        'unit_price' => $product->price,
        'line_total' => $product->price,
        'status'     => $status,
    ]);
}

// ── Fix #4: all-cancelled guard ──────────────────────────────────────

test('recalcCustomerShipping zeros all fields when every item is cancelled', function () {
    $svc = app(PromoService::class);
    [$admin, $trip, $area, $cust] = makeTripWithArea(20000);

    $product = Product::create([
        'trip_id' => $trip->id, 'product_code' => 'AA_CAN',
        'price' => 100000, 'weight_gram' => 500, 'status' => 'active',
    ]);

    $order = Order::factory()->create([
        'trip_id'          => $trip->id,
        'customer_id'      => $cust->id,
        'created_by'       => $admin->id,
        'shipping_area_id' => $area->id,
        'subtotal'         => 100000,
        'total_amount'     => 120000,
        'shipping_fee'     => 20000,
    ]);
    makeItem($order, $product, 'cancelled');

    $svc->recalcCustomerShipping($cust->id, $trip->id);
    $order->refresh();

    expect((float) $order->subtotal)->toBe(0.0)
        ->and((float) $order->total_amount)->toBe(0.0)
        ->and((float) $order->shipping_fee)->toBe(0.0)
        ->and((float) $order->discount_amount)->toBe(0.0);
});

test('recalcCustomerShipping zeros all fields when every item is sold_out', function () {
    $svc = app(PromoService::class);
    [$admin, $trip, $area, $cust] = makeTripWithArea(20000);

    $product = Product::create([
        'trip_id' => $trip->id, 'product_code' => 'AA_SO',
        'price' => 100000, 'weight_gram' => 500, 'status' => 'active',
    ]);

    $order = Order::factory()->create([
        'trip_id'          => $trip->id,
        'customer_id'      => $cust->id,
        'created_by'       => $admin->id,
        'shipping_area_id' => $area->id,
        'subtotal'         => 100000,
        'total_amount'     => 120000,
        'shipping_fee'     => 20000,
    ]);
    makeItem($order, $product, 'sold_out');

    $svc->recalcCustomerShipping($cust->id, $trip->id);
    $order->refresh();

    expect((float) $order->total_amount)->toBe(0.0)
        ->and((float) $order->shipping_fee)->toBe(0.0);
});

test('recalcCustomerShipping correctly handles mix of cancelled and active items', function () {
    $svc = app(PromoService::class);
    [$admin, $trip, $area, $cust] = makeTripWithArea(10000);

    $product = Product::create([
        'trip_id' => $trip->id, 'product_code' => 'AA_MIX',
        'price' => 100000, 'weight_gram' => 500, 'status' => 'active',
    ]);

    $order = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $cust->id,
        'created_by' => $admin->id, 'shipping_area_id' => $area->id,
        'subtotal' => 0, 'total_amount' => 0,
    ]);
    makeItem($order, $product, 'pending');
    makeItem($order, $product, 'cancelled');

    $svc->recalcCustomerShipping($cust->id, $trip->id);
    $order->refresh();

    // Only 1 active item (500g -> 1kg -> 10000 shipping)
    expect((float) $order->subtotal)->toBe(100000.0)
        ->and((float) $order->shipping_fee)->toBe(10000.0)
        ->and((float) $order->total_amount)->toBe(110000.0);
});

// ── Flat-fee area + promo subsidy interaction ────────────────────────

test('flat-fee shipping is correctly used when subsidy caps against flat amount', function () {
    $svc   = app(PromoService::class);
    $admin = User::factory()->admin()->create();
    $trip  = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $area  = ShippingArea::factory()->flatFee(10000)->create();
    $cust  = Customer::factory()->create([
        'type' => 'customer', 'default_shipping_area_id' => $area->id, 'created_by' => $admin->id,
    ]);
    $product = Product::create([
        'trip_id' => $trip->id, 'product_code' => 'BB_01',
        'price' => 100000, 'weight_gram' => 200, 'status' => 'active',
    ]);

    // Rule offers 50000 shipping subsidy but flat fee is only 10000 -> subsidy capped at 10000
    PromoRule::create([
        'name' => 'free ship', 'min_items' => 1,
        'discount_per_item' => 0, 'discount_flat' => 0, 'max_shipping_subsidy' => 50000,
        'eligible_customer_types' => ['customer'], 'excluded_product_codes' => [],
        'trip_id' => $trip->id, 'is_active' => true,
    ]);

    $order = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $cust->id,
        'created_by' => $admin->id, 'shipping_area_id' => $area->id,
        'subtotal' => 0, 'total_amount' => 0,
    ]);
    makeItem($order, $product, 'pending');

    $calc = $svc->recalculate($order->fresh('items.product'));

    expect((float) $calc['shipping_fee'])->toBe(10000.0)
        ->and((float) $calc['shipping_discount'])->toBe(10000.0)  // capped at flat fee, not 50000
        ->and((float) $calc['total_amount'])->toBe(100000.0);     // 100000 + 10000 - 10000
});

test('eligible_customer_types null means all types can use the promo', function () {
    $svc   = app(PromoService::class);
    $admin = User::factory()->admin()->create();
    $trip  = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $area  = ShippingArea::factory()->create(['price_per_kg' => 10000]);

    PromoRule::create([
        'name' => 'all types', 'min_items' => 1,
        'discount_per_item' => 0, 'discount_flat' => 20000, 'max_shipping_subsidy' => 0,
        'eligible_customer_types' => null, // null = applies to all
        'excluded_product_codes' => [],
        'trip_id' => $trip->id, 'is_active' => true,
    ]);

    $product = Product::create([
        'trip_id' => $trip->id, 'product_code' => 'CC_ALLTYPE',
        'price' => 100000, 'weight_gram' => 100, 'status' => 'active',
    ]);

    foreach (['customer', 'reseller', 'selected_customer'] as $type) {
        $cust = Customer::factory()->create([
            'type' => $type, 'default_shipping_area_id' => $area->id, 'created_by' => $admin->id,
        ]);
        $order = Order::factory()->create([
            'trip_id' => $trip->id, 'customer_id' => $cust->id,
            'created_by' => $admin->id, 'shipping_area_id' => $area->id,
            'subtotal' => 0, 'total_amount' => 0,
        ]);
        makeItem($order, $product, 'pending');
        $calc = $svc->recalculate($order->fresh('items.product'));
        expect((int) $calc['discount_amount'])->toBe(20000, "type {$type} should get discount");
    }
});

test('inactive promo rule is never applied', function () {
    $svc   = app(PromoService::class);
    $admin = User::factory()->admin()->create();
    $trip  = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $area  = ShippingArea::factory()->create(['price_per_kg' => 10000]);
    $cust  = Customer::factory()->create([
        'type' => 'customer', 'default_shipping_area_id' => $area->id, 'created_by' => $admin->id,
    ]);

    PromoRule::create([
        'name' => 'inactive rule', 'min_items' => 1,
        'discount_per_item' => 0, 'discount_flat' => 50000, 'max_shipping_subsidy' => 0,
        'eligible_customer_types' => ['customer'], 'excluded_product_codes' => [],
        'trip_id' => $trip->id, 'is_active' => false, // INACTIVE
    ]);

    $product = Product::create([
        'trip_id' => $trip->id, 'product_code' => 'CC_INACT',
        'price' => 100000, 'weight_gram' => 100, 'status' => 'active',
    ]);
    $order = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $cust->id,
        'created_by' => $admin->id, 'shipping_area_id' => $area->id,
        'subtotal' => 0, 'total_amount' => 0,
    ]);
    makeItem($order, $product, 'pending');
    $calc = $svc->recalculate($order->fresh('items.product'));

    expect((int) $calc['discount_amount'])->toBe(0);
});

test('appliesTo returns false when customer type not in eligible list', function () {
    $rule = \App\Models\PromoRule::make([
        'is_active'               => true,
        'min_items'               => 1,
        'eligible_customer_types' => ['reseller'],
        'discount_flat'           => 10000,
        'discount_per_item'       => 0,
        'max_shipping_subsidy'    => 0,
        'excluded_product_codes'  => [],
    ]);

    expect($rule->appliesTo('customer', 1))->toBeFalse()
        ->and($rule->appliesTo('reseller', 1))->toBeTrue();
});

test('appliesTo handles double-encoded JSON string in eligible_customer_types', function () {
    // Fix #2 guard: if DB stored "[\"customer\"]" as a string instead of JSON,
    // appliesTo must still decode and match correctly.
    $rule = \App\Models\PromoRule::make([
        'is_active'  => true,
        'min_items'  => 1,
        'discount_flat' => 5000, 'discount_per_item' => 0, 'max_shipping_subsidy' => 0,
        'excluded_product_codes' => [],
    ]);
    // Bypass the cast by setting the raw attribute directly
    $rule->setRawAttributes(array_merge($rule->getAttributes(), [
        'eligible_customer_types' => '["customer","reseller"]', // double-encoded string
    ]));

    expect($rule->appliesTo('customer', 1))->toBeTrue()
        ->and($rule->appliesTo('reseller', 1))->toBeTrue()
        ->and($rule->appliesTo('selected_customer', 1))->toBeFalse();
});