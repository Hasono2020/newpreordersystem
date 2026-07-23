<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\PromoRule;
use App\Models\ShippingArea;
use App\Models\Trip;
use App\Models\Customer;
use App\Models\User;
use App\Services\PromoService;

/*
 * These tests lock in the money math in PromoService + ShippingArea + PromoRule.
 *
 * Reference formulas (from the code under test):
 *   ShippingArea::calcChargeableKg(grams):
 *     <=0 -> 0 ; <=1320 -> 1kg ; else ceil((grams-320)/1000)
 *   ShippingArea->calcShippingFee(grams) = chargeableKg * price_per_kg
 *   PromoRule->calculateDiscount(count):
 *     per_item discount (if >0) = discount_per_item * count ; else discount_flat
 *   shippingDiscount = min(shippingFee, max_shipping_subsidy)
 *   order total = max(0, subtotal - discount + shippingFee - shippingDiscount)
 */

// ── Local builders (no product/item factories exist in this project) ──

function makeTrip(): Trip
{
    $admin = User::factory()->admin()->create();
    return Trip::factory()->open()->create(['created_by' => $admin->id]);
}

function makeProduct(Trip $trip, int $weightGram = 500, float $price = 100000, array $extra = []): Product
{
    return Product::create(array_merge([
        'trip_id'      => $trip->id,
        'product_code' => 'AA_' . fake()->unique()->numerify('###'),
        'price'        => $price,
        'weight_gram'  => $weightGram,
        'status'       => 'active',
    ], $extra));
}

function makeOrderWithItems(Trip $trip, Customer $customer, ShippingArea $area, array $items, ?User $by = null): Order
{
    $by = $by ?? User::factory()->admin()->create();
    $order = Order::factory()->create([
        'trip_id'          => $trip->id,
        'customer_id'      => $customer->id,
        'shipping_area_id' => $area->id,
        'created_by'       => $by->id,
        'subtotal'         => 0,
        'total_amount'     => 0,
    ]);
    foreach ($items as $it) {
        $qty   = $it['qty'] ?? 1;
        $price = $it['price'] ?? $it['product']->price;
        OrderItem::create([
            'order_id'   => $order->id,
            'product_id' => $it['product']->id,
            'quantity'   => $qty,
            'unit_price' => $price,
            'line_total' => $price * $qty,
            'status'     => $it['status'] ?? 'pending',
        ]);
    }
    return $order->fresh('items.product');
}

// ── ShippingArea kg/fee formula (pure, deterministic) ────────────────

test('chargeable kg follows the tier formula', function () {
    expect(ShippingArea::calcChargeableKg(0))->toBe(0.0)
        ->and(ShippingArea::calcChargeableKg(500))->toBe(1.0)
        ->and(ShippingArea::calcChargeableKg(1320))->toBe(1.0)   // boundary: still 1kg
        ->and(ShippingArea::calcChargeableKg(1321))->toBe(2.0)   // just over -> 2kg
        ->and(ShippingArea::calcChargeableKg(2320))->toBe(2.0)
        ->and(ShippingArea::calcChargeableKg(2321))->toBe(3.0);
});

test('shipping fee is chargeable kg times price per kg', function () {
    $area = ShippingArea::factory()->create(['price_per_kg' => 20000]);
    // 500g -> 1kg -> 20000 ; 1351g -> 2kg -> 40000
    expect($area->calcShippingFee(500))->toBe(20000.0)
        ->and($area->calcShippingFee(1351))->toBe(40000.0);
});

// ── Single-order promo + shipping math ───────────────────────────────

test('order with no promo: total = subtotal + shipping', function () {
    $svc   = app(PromoService::class);
    $trip  = makeTrip();
    $area  = ShippingArea::factory()->create(['price_per_kg' => 20000]);
    $cust  = Customer::factory()->create(['type' => 'customer', 'default_shipping_area_id' => $area->id]);
    $prod  = makeProduct($trip, weightGram: 500, price: 100000);

    $order = makeOrderWithItems($trip, $cust, $area, [
        ['product' => $prod, 'qty' => 2, 'price' => 100000],
    ]);

    $calc = $svc->recalculate($order);

    // subtotal 200000 ; weight 1000g -> 1kg -> shipping 20000 ; no promo
    expect((int) $calc['subtotal'])->toBe(200000)
        ->and((float) $calc['shipping_fee'])->toBe(20000.0)
        ->and((int) $calc['discount_amount'])->toBe(0)
        ->and((float) $calc['total_amount'])->toBe(220000.0);
});

test('per-item promo applies at threshold and discounts correctly', function () {
    $svc  = app(PromoService::class);
    $trip = makeTrip();
    $area = ShippingArea::factory()->create(['price_per_kg' => 20000]);
    $cust = Customer::factory()->create(['type' => 'customer', 'default_shipping_area_id' => $area->id]);
    $prod = makeProduct($trip, weightGram: 100, price: 50000);

    // Rule: 3+ items, Rp 5.000 off per item, no shipping subsidy
    PromoRule::create([
        'name' => '3+ items', 'min_items' => 3,
        'discount_per_item' => 5000, 'discount_flat' => 0, 'max_shipping_subsidy' => 0,
        'eligible_customer_types' => ['customer'], 'excluded_product_codes' => [],
        'trip_id' => $trip->id, 'is_active' => true,
    ]);

    // 2 items -> below threshold -> no discount
    $order2 = makeOrderWithItems($trip, $cust, $area, [['product' => $prod, 'qty' => 2, 'price' => 50000]]);
    expect((int) $svc->recalculate($order2)['discount_amount'])->toBe(0);

    // 3 items -> threshold met -> 3 * 5000 = 15000 discount
    $cust2  = Customer::factory()->create(['type' => 'customer', 'default_shipping_area_id' => $area->id]);
    $order3 = makeOrderWithItems($trip, $cust2, $area, [['product' => $prod, 'qty' => 3, 'price' => 50000]]);
    expect((int) $svc->recalculate($order3)['discount_amount'])->toBe(15000);
});

test('shipping subsidy is capped at the actual shipping fee', function () {
    $svc  = app(PromoService::class);
    $trip = makeTrip();
    $area = ShippingArea::factory()->create(['price_per_kg' => 20000]); // 1kg = 20000
    $cust = Customer::factory()->create(['type' => 'customer', 'default_shipping_area_id' => $area->id]);
    $prod = makeProduct($trip, weightGram: 200, price: 50000);

    // Rule offers up to 50000 shipping subsidy, but fee is only 20000 -> capped at 20000
    PromoRule::create([
        'name' => 'free ship', 'min_items' => 1,
        'discount_per_item' => 0, 'discount_flat' => 0, 'max_shipping_subsidy' => 50000,
        'eligible_customer_types' => ['customer'], 'excluded_product_codes' => [],
        'trip_id' => $trip->id, 'is_active' => true,
    ]);

    $order = makeOrderWithItems($trip, $cust, $area, [['product' => $prod, 'qty' => 1, 'price' => 50000]]);
    $calc  = $svc->recalculate($order);

    expect((float) $calc['shipping_fee'])->toBe(20000.0)
        ->and((float) $calc['shipping_discount'])->toBe(20000.0)   // capped, not 50000
        ->and((float) $calc['total_amount'])->toBe(50000.0);        // 50000 - 0 + 20000 - 20000
});

test('product flagged excluded_from_promo does not count toward threshold', function () {
    $svc  = app(PromoService::class);
    $trip = makeTrip();
    $area = ShippingArea::factory()->create(['price_per_kg' => 10000]);
    $cust = Customer::factory()->create(['type' => 'customer', 'default_shipping_area_id' => $area->id]);

    $normal   = makeProduct($trip, weightGram: 100, price: 50000);
    $excluded = makeProduct($trip, weightGram: 100, price: 50000, extra: ['excluded_from_promo' => true]);

    PromoRule::create([
        'name' => '3+ items', 'min_items' => 3,
        'discount_per_item' => 5000, 'discount_flat' => 0, 'max_shipping_subsidy' => 0,
        'eligible_customer_types' => ['customer'], 'excluded_product_codes' => [],
        'trip_id' => $trip->id, 'is_active' => true,
    ]);

    // 2 normal + 2 excluded = 4 total items, but only 2 count -> below threshold -> no discount
    $order = makeOrderWithItems($trip, $cust, $area, [
        ['product' => $normal,   'qty' => 2, 'price' => 50000],
        ['product' => $excluded, 'qty' => 2, 'price' => 50000],
    ]);

    expect((int) $svc->recalculate($order)['discount_amount'])->toBe(0);
});

test('rule-level excluded_product_codes prefix is ignored for threshold', function () {
    $svc  = app(PromoService::class);
    $trip = makeTrip();
    $area = ShippingArea::factory()->create(['price_per_kg' => 10000]);
    $cust = Customer::factory()->create(['type' => 'customer', 'default_shipping_area_id' => $area->id]);

    // code_prefix = part before first underscore, uppercased. 'ZZ_01' -> 'ZZ'
    $eligible = makeProduct($trip, weightGram: 100, price: 50000, extra: ['product_code' => 'AA_01']);
    $blocked  = makeProduct($trip, weightGram: 100, price: 50000, extra: ['product_code' => 'ZZ_01']);

    PromoRule::create([
        'name' => '3+ items', 'min_items' => 3,
        'discount_per_item' => 5000, 'discount_flat' => 0, 'max_shipping_subsidy' => 0,
        'eligible_customer_types' => ['customer'], 'excluded_product_codes' => ['ZZ'],
        'trip_id' => $trip->id, 'is_active' => true,
    ]);

    // 2 eligible + 2 blocked(ZZ) = only 2 count -> below 3 -> no discount
    $order = makeOrderWithItems($trip, $cust, $area, [
        ['product' => $eligible, 'qty' => 2, 'price' => 50000],
        ['product' => $blocked,  'qty' => 2, 'price' => 50000],
    ]);

    expect((int) $svc->recalculate($order)['discount_amount'])->toBe(0);
});

test('best-deal selection picks the rule with the highest total benefit', function () {
    $svc  = app(PromoService::class);
    $trip = makeTrip();
    $area = ShippingArea::factory()->create(['price_per_kg' => 10000]);
    $cust = Customer::factory()->create(['type' => 'customer', 'default_shipping_area_id' => $area->id]);
    $prod = makeProduct($trip, weightGram: 100, price: 50000);

    // Two competing rules both apply at 5 items:
    //  A: flat 20000 discount        -> benefit 20000
    //  B: 5000/item (5*5000=25000)   -> benefit 25000  (should win)
    PromoRule::create([
        'name' => 'flat', 'min_items' => 5,
        'discount_per_item' => 0, 'discount_flat' => 20000, 'max_shipping_subsidy' => 0,
        'eligible_customer_types' => ['customer'], 'excluded_product_codes' => [],
        'trip_id' => $trip->id, 'is_active' => true,
    ]);
    PromoRule::create([
        'name' => 'per-item', 'min_items' => 5,
        'discount_per_item' => 5000, 'discount_flat' => 0, 'max_shipping_subsidy' => 0,
        'eligible_customer_types' => ['customer'], 'excluded_product_codes' => [],
        'trip_id' => $trip->id, 'is_active' => true,
    ]);

    $order = makeOrderWithItems($trip, $cust, $area, [['product' => $prod, 'qty' => 5, 'price' => 50000]]);
    expect((int) $svc->recalculate($order)['discount_amount'])->toBe(25000);
});

// ── Combined shipping across a customer's orders in a trip ───────────

test('combined shipping is charged once on the anchor order', function () {
    $svc  = app(PromoService::class);
    $trip = makeTrip();
    $area = ShippingArea::factory()->create(['price_per_kg' => 20000]);
    $cust = Customer::factory()->create(['type' => 'customer', 'default_shipping_area_id' => $area->id]);
    $prod = makeProduct($trip, weightGram: 500, price: 100000);

    // 3 separate orders, each 500g. Combined weight = 1500g -> 2kg -> 40000 shipping.
    $o1 = makeOrderWithItems($trip, $cust, $area, [['product' => $prod, 'qty' => 1, 'price' => 100000]]);
    $o2 = makeOrderWithItems($trip, $cust, $area, [['product' => $prod, 'qty' => 1, 'price' => 100000]]);
    $o3 = makeOrderWithItems($trip, $cust, $area, [['product' => $prod, 'qty' => 1, 'price' => 100000]]);

    $svc->recalcCustomerShipping($cust->id, $trip->id);

    $o1->refresh(); $o2->refresh(); $o3->refresh();

    // Anchor (oldest = o1) carries all shipping; others zero
    expect((float) $o1->shipping_fee)->toBe(40000.0)
        ->and((float) $o2->shipping_fee)->toBe(0.0)
        ->and((float) $o3->shipping_fee)->toBe(0.0);

    // Sum of all order totals = combined subtotal (300000) + shipping once (40000)
    $sumTotals = (float) $o1->total_amount + (float) $o2->total_amount + (float) $o3->total_amount;
    expect($sumTotals)->toBe(340000.0);
});

test('combined promo threshold is met across multiple orders and applied once', function () {
    $svc  = app(PromoService::class);
    $trip = makeTrip();
    $area = ShippingArea::factory()->create(['price_per_kg' => 10000]);
    $cust = Customer::factory()->create(['type' => 'customer', 'default_shipping_area_id' => $area->id]);
    $prod = makeProduct($trip, weightGram: 100, price: 50000);

    // Rule: 5+ items -> 5000/item discount
    PromoRule::create([
        'name' => '5+ combined', 'min_items' => 5,
        'discount_per_item' => 5000, 'discount_flat' => 0, 'max_shipping_subsidy' => 0,
        'eligible_customer_types' => ['customer'], 'excluded_product_codes' => [],
        'trip_id' => $trip->id, 'is_active' => true,
    ]);

    // Two orders, 3 + 3 = 6 items combined (neither alone reaches 5)
    $o1 = makeOrderWithItems($trip, $cust, $area, [['product' => $prod, 'qty' => 3, 'price' => 50000]]);
    $o2 = makeOrderWithItems($trip, $cust, $area, [['product' => $prod, 'qty' => 3, 'price' => 50000]]);

    $svc->recalcCustomerShipping($cust->id, $trip->id);
    $o1->refresh(); $o2->refresh();

    // Discount = 6 items * 5000 = 30000, applied to anchor only
    expect((int) $o1->discount_amount)->toBe(30000)
        ->and((int) $o2->discount_amount)->toBe(0);
});

test('cancelled items are excluded from totals', function () {
    $svc  = app(PromoService::class);
    $trip = makeTrip();
    $area = ShippingArea::factory()->create(['price_per_kg' => 10000]);
    $cust = Customer::factory()->create(['type' => 'customer', 'default_shipping_area_id' => $area->id]);
    $prod = makeProduct($trip, weightGram: 500, price: 100000);

    // One active item + one cancelled item
    $order = makeOrderWithItems($trip, $cust, $area, [
        ['product' => $prod, 'qty' => 1, 'price' => 100000, 'status' => 'pending'],
        ['product' => $prod, 'qty' => 1, 'price' => 100000, 'status' => 'cancelled'],
    ]);

    $calc = $svc->recalculate($order);
    // Only the active item counts: subtotal 100000, weight 500g -> 1kg -> 10000 shipping
    expect((int) $calc['subtotal'])->toBe(100000)
        ->and((int) $calc['shipping_weight_gram'])->toBe(500)
        ->and((float) $calc['total_amount'])->toBe(110000.0);
});