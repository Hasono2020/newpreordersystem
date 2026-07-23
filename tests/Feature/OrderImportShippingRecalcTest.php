<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShippingArea;
use App\Models\Trip;
use App\Services\OrderImportService;

/*
 * Tests for the bulk order importer's missing post-import recalculation.
 *
 * Each row in an import becomes its own order, and each row's shipping
 * fee is computed from ONLY that row's product weight — because at
 * insert time, a customer's other rows in the same file aren't orders
 * yet to combine with. A comment in the code claimed
 * recalcCustomerShipping() ran afterward to fix this up; it never did.
 *
 * Net effect before this fix: a customer with 3 rows, each under the
 * 1kg minimum, got charged three separate 1kg shipments instead of one
 * combined shipment — and only their first row ever received any promo
 * shipping subsidy.
 *
 * Column layout (matches the DIBUAT OLEH / NO / NAMA / IG-WA / NO HP /
 * KOTA / KODE / WARNA / SIZE / HARGA SATUAN / DP / TGL DP / AN / KET
 * export header):
 *   [2]=name [3]=cs agent [4]=phone [5]=area [6]=product code
 *   [7]=color [8]=size [9]=price [10]=dp [11]=dp date [12]=an [13]=ket
 */
function importRow(string $name, string $phone, string $area, string $code, string $color = '', string $size = ''): array
{
    return ['', '', $name, '', $phone, $area, $code, $color, $size, '', '', '', '', ''];
}

test('shipping is combined across a customer\'s rows after import, not charged once per row', function () {
    $admin = $this->adminUser();
    $trip  = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $area  = ShippingArea::factory()->create(['name' => 'JAKARTA', 'price_per_kg' => 25000, 'flat_fee' => null]);

    // Three products, each light enough that individually they'd each
    // round up to the 1kg minimum, but combined they're still under it.
    Product::create(['trip_id' => $trip->id, 'product_code' => 'IM_A', 'price' => 50000, 'weight_gram' => 350, 'status' => 'active']);
    Product::create(['trip_id' => $trip->id, 'product_code' => 'IM_B', 'price' => 50000, 'weight_gram' => 400, 'status' => 'active']);
    Product::create(['trip_id' => $trip->id, 'product_code' => 'IM_C', 'price' => 50000, 'weight_gram' => 300, 'status' => 'active']);

    $rows = [
        importRow('Budi Import', '081234509001', 'JAKARTA', 'IM_A'),
        importRow('Budi Import', '081234509001', '', 'IM_B'), // blank KOTA inherits customer's area
        importRow('Budi Import', '081234509001', '', 'IM_C'),
    ];

    $result = app(OrderImportService::class)->importRows($rows, $trip, $admin->id);

    expect($result['imported'])->toBe(3)
        ->and($result['recalculated'])->toBe(1); // one distinct customer

    $customer = Customer::where('phone', '081234509001')->first();
    $orders   = Order::where('customer_id', $customer->id)->orderBy('id')->get();

    expect($orders)->toHaveCount(3);

    // 350+400+300 = 1050g combined -> still 1kg tier -> Rp 25,000 ONCE,
    // charged on the anchor (oldest) order; the other two carry none.
    $totalShippingCharged = $orders->sum('shipping_fee');
    expect($totalShippingCharged)->toBe(25000.0)
        ->and((float) $orders->first()->shipping_weight_gram)->toBe(1050.0);
});

test('without combining, the same rows would have been overcharged 3x — this proves the fix, not just the happy path', function () {
    // Same setup as above, but weights chosen so PER-ROW each already
    // hits 1kg on its own (this documents the bug's actual shape: before
    // the fix, total charged would have been 3 x the per-kg rate).
    $admin = $this->adminUser();
    $trip  = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $area  = ShippingArea::factory()->create(['name' => 'SURABAYA', 'price_per_kg' => 30000, 'flat_fee' => null]);

    Product::create(['trip_id' => $trip->id, 'product_code' => 'IM_X', 'price' => 40000, 'weight_gram' => 500, 'status' => 'active']);
    Product::create(['trip_id' => $trip->id, 'product_code' => 'IM_Y', 'price' => 40000, 'weight_gram' => 500, 'status' => 'active']);

    $rows = [
        importRow('Siti Import', '081234509002', 'SURABAYA', 'IM_X'),
        importRow('Siti Import', '081234509002', '', 'IM_Y'),
    ];

    app(OrderImportService::class)->importRows($rows, $trip, $admin->id);

    $customer = Customer::where('phone', '081234509002')->first();
    $orders   = Order::where('customer_id', $customer->id)->get();

    // 500+500=1000g combined -> still 1kg -> Rp 30,000 once.
    // Uncombined, this would have been 500g->1kg and 500g->1kg = Rp 60,000.
    expect($orders->sum('shipping_fee'))->toBe(30000.0);
});

test('a promo shipping subsidy applies across all of a customer\'s import rows, not just their first', function () {
    $admin = $this->adminUser();
    $trip  = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $area  = ShippingArea::factory()->create(['name' => 'MEDAN', 'price_per_kg' => 20000, 'flat_fee' => null]);

    \App\Models\PromoRule::create([
        'name' => 'Free Ship 2+', 'min_items' => 2, 'discount_flat' => 0,
        'max_shipping_subsidy' => 999999, 'eligible_customer_types' => ['customer'],
        'excluded_product_codes' => [], 'is_active' => true, 'trip_id' => $trip->id,
    ]);

    Product::create(['trip_id' => $trip->id, 'product_code' => 'IM_P', 'price' => 40000, 'weight_gram' => 500, 'status' => 'active']);
    Product::create(['trip_id' => $trip->id, 'product_code' => 'IM_Q', 'price' => 40000, 'weight_gram' => 500, 'status' => 'active']);

    $rows = [
        importRow('Dewi Import', '081234509003', 'MEDAN', 'IM_P'),
        importRow('Dewi Import', '081234509003', '', 'IM_Q'),
    ];

    app(OrderImportService::class)->importRows($rows, $trip, $admin->id);

    $customer = Customer::where('phone', '081234509003')->first();
    $orders   = Order::where('customer_id', $customer->id)->get();

    // Combined shipping fee should be fully subsidised (free shipping),
    // not just subsidised on whichever row happened to be inserted first.
    expect($orders->sum('shipping_discount'))->toBe($orders->sum('shipping_fee'))
        ->and($orders->sum('shipping_fee'))->toBeGreaterThan(0.0);
});

test('customers who only have one row in the import are unaffected', function () {
    $admin = $this->adminUser();
    $trip  = Trip::factory()->open()->create(['created_by' => $admin->id]);
    ShippingArea::factory()->create(['name' => 'BANDUNG', 'price_per_kg' => 15000, 'flat_fee' => null]);

    Product::create(['trip_id' => $trip->id, 'product_code' => 'IM_S', 'price' => 40000, 'weight_gram' => 500, 'status' => 'active']);

    $rows = [importRow('Solo Import', '081234509004', 'BANDUNG', 'IM_S')];

    $result = app(OrderImportService::class)->importRows($rows, $trip, $admin->id);

    expect($result['imported'])->toBe(1)
        ->and($result['recalculated'])->toBe(1);

    $order = Order::whereHas('customer', fn($q) => $q->where('phone', '081234509004'))->first();
    expect((float) $order->shipping_fee)->toBe(15000.0); // 500g -> 1kg -> 15,000, unchanged by recalc
});
