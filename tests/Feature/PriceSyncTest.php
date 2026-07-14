<?php

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ShippingArea;
use App\Models\Supplier;
use App\Models\Trip;

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Create a product with a known price in the given trip.
 */
function syncProduct(Trip $trip, float $price = 500000): Product
{
    $supplier = Supplier::factory()->create();
    return Product::create([
        'trip_id'      => $trip->id,
        'product_code' => 'TST_' . rand(1, 9999),
        'price'        => $price,
        'weight_gram'  => 500,
        'status'       => 'active',
        'supplier_id'  => $supplier->id,
    ]);
}

/**
 * Create an order with one order item and an optional payment.
 */
function syncOrder(
    $test,
    Trip $trip,
    Product $product,
    ShippingArea $area,
    float $unitPrice,
    int $qty = 1,
    float $paid = 0
): Order {
    $admin    = $test->adminUser();
    $customer = $test->customer($admin);
    $lineTotal = $unitPrice * $qty;

    $order = Order::factory()->create([
        'trip_id'          => $trip->id,
        'customer_id'      => $customer->id,
        'created_by'       => $admin->id,
        'shipping_area_id' => $area->id,
        'subtotal'         => $lineTotal,
        'total_amount'     => $lineTotal,
        'deposit_paid'     => $paid,
        'payment_status'   => $paid <= 0 ? 'unpaid' : ($paid >= $lineTotal ? 'paid' : 'partial'),
    ]);

    OrderItem::create([
        'order_id'   => $order->id,
        'product_id' => $product->id,
        'quantity'   => $qty,
        'unit_price' => $unitPrice,
        'line_total' => $lineTotal,
        'status'     => 'confirmed',
    ]);

    if ($paid > 0) {
        Payment::factory()->create([
            'order_id'    => $order->id,
            'recorded_by' => $admin->id,
            'amount'      => $paid,
            'type'        => $paid >= $lineTotal ? 'full' : 'partial',
        ]);
    }

    return $order;
}

// ── Product price sync: order item prices ─────────────────────────────────────

test('updating product price updates unit_price and line_total on open-trip order items', function () {
    $admin   = $this->adminUser();
    $trip    = $this->openTrip();
    $area    = $this->shippingArea();
    $product = syncProduct($trip, 500000);

    $order = syncOrder($this, $trip, $product, $area, 500000);
    $item  = $order->items()->first();

    $this->actingAs($admin)->put("/products/{$product->id}", [
        'trip_id'      => $trip->id,
        'supplier_id'  => $product->supplier_id,
        'product_code' => $product->product_code,
        'price'        => 600000,
        'weight_gram'  => $product->weight_gram,
        'status'       => $product->status,
    ])->assertRedirect();

    $item->refresh();
    expect((float) $item->unit_price)->toBe(600000.0)
        ->and((float) $item->line_total)->toBe(600000.0);
});

test('updating product price does NOT affect items in non-open trips', function () {
    $admin   = $this->adminUser();
    $openTrip   = $this->openTrip();
    $closedTrip = Trip::factory()->closed()->create(['created_by' => $admin->id]);
    $area       = $this->shippingArea();

    $openProduct   = syncProduct($openTrip,   500000);
    $closedProduct = syncProduct($closedTrip, 500000);

    $openOrder   = syncOrder($this, $openTrip,   $openProduct,   $area, 500000);
    $closedOrder = syncOrder($this, $closedTrip, $closedProduct, $area, 500000);

    $closedItem = $closedOrder->items()->first();

    $this->actingAs($admin)->put("/products/{$closedProduct->id}", [
        'trip_id'      => $closedTrip->id,
        'supplier_id'  => $closedProduct->supplier_id,
        'product_code' => $closedProduct->product_code,
        'price'        => 800000,
        'weight_gram'  => $closedProduct->weight_gram,
        'status'       => $closedProduct->status,
    ])->assertRedirect();

    // Closed-trip item must not change
    expect((float) $closedItem->fresh()->unit_price)->toBe(500000.0);
});

test('product price sync recalculates order subtotal and total', function () {
    $admin   = $this->adminUser();
    $trip    = $this->openTrip();
    $area    = ShippingArea::factory()->create(['price_per_kg' => 0]); // zero shipping for isolation
    $product = syncProduct($trip, 500000);

    $order = syncOrder($this, $trip, $product, $area, 500000, qty: 2);

    $this->actingAs($admin)->put("/products/{$product->id}", [
        'trip_id'      => $trip->id,
        'supplier_id'  => $product->supplier_id,
        'product_code' => $product->product_code,
        'price'        => 700000,
        'weight_gram'  => $product->weight_gram,
        'status'       => $product->status,
    ])->assertRedirect();

    $order->refresh();
    // 2 items × 700,000 = 1,400,000
    expect((float) $order->total_amount)->toBe(1400000.0);
});

// ── Product price sync: payment status downgrade ──────────────────────────────

test('price increase on a fully-paid order downgrades payment status to partial', function () {
    $admin   = $this->adminUser();
    $trip    = $this->openTrip();
    $area    = ShippingArea::factory()->create(['price_per_kg' => 0]);
    $product = syncProduct($trip, 500000);

    // Order fully paid at 500,000
    $order = syncOrder($this, $trip, $product, $area, 500000, paid: 500000);
    expect($order->payment_status)->toBe('paid');

    // Raise price to 600,000 — customer now owes 100,000 more
    $this->actingAs($admin)->put("/products/{$product->id}", [
        'trip_id'      => $trip->id,
        'supplier_id'  => $product->supplier_id,
        'product_code' => $product->product_code,
        'price'        => 600000,
        'weight_gram'  => $product->weight_gram,
        'status'       => $product->status,
    ])->assertRedirect();

    $order->refresh();
    expect($order->payment_status)->toBe('partial')
        ->and((float) $order->deposit_paid)->toBe(500000.0); // payment unchanged
});

test('price increase on an unpaid order keeps it unpaid', function () {
    $admin   = $this->adminUser();
    $trip    = $this->openTrip();
    $area    = ShippingArea::factory()->create(['price_per_kg' => 0]);
    $product = syncProduct($trip, 500000);

    $order = syncOrder($this, $trip, $product, $area, 500000, paid: 0);

    $this->actingAs($admin)->put("/products/{$product->id}", [
        'trip_id'      => $trip->id,
        'supplier_id'  => $product->supplier_id,
        'product_code' => $product->product_code,
        'price'        => 700000,
        'weight_gram'  => $product->weight_gram,
        'status'       => $product->status,
    ])->assertRedirect();

    expect($order->fresh()->payment_status)->toBe('unpaid');
});

test('price decrease on a partially-paid order can flip it to fully paid', function () {
    $admin   = $this->adminUser();
    $trip    = $this->openTrip();
    $area    = ShippingArea::factory()->create(['price_per_kg' => 0]);
    $product = syncProduct($trip, 500000);

    // Customer paid 400,000 on a 500,000 order (partial)
    $order = syncOrder($this, $trip, $product, $area, 500000, paid: 400000);
    expect($order->payment_status)->toBe('partial');

    // Drop price to 400,000 — now fully covered
    $this->actingAs($admin)->put("/products/{$product->id}", [
        'trip_id'      => $trip->id,
        'supplier_id'  => $product->supplier_id,
        'product_code' => $product->product_code,
        'price'        => 400000,
        'weight_gram'  => $product->weight_gram,
        'status'       => $product->status,
    ])->assertRedirect();

    expect($order->fresh()->payment_status)->toBe('paid');
});

test('payment records are untouched after product price sync', function () {
    $admin   = $this->adminUser();
    $trip    = $this->openTrip();
    $area    = ShippingArea::factory()->create(['price_per_kg' => 0]);
    $product = syncProduct($trip, 500000);

    $order = syncOrder($this, $trip, $product, $area, 500000, paid: 300000);
    $paymentBefore = $order->payments()->first();

    $this->actingAs($admin)->put("/products/{$product->id}", [
        'trip_id'      => $trip->id,
        'supplier_id'  => $product->supplier_id,
        'product_code' => $product->product_code,
        'price'        => 700000,
        'weight_gram'  => $product->weight_gram,
        'status'       => $product->status,
    ])->assertRedirect();

    $paymentAfter = $paymentBefore->fresh();
    // Payment record itself must not change
    expect((float) $paymentAfter->amount)->toBe(300000.0)
        ->and($paymentAfter->type)->toBe('partial')
        ->and($paymentAfter->voided_at)->toBeNull();
});

// ── Product price sync: activity log ─────────────────────────────────────────

test('product price sync writes an activity log entry', function () {
    $admin   = $this->adminUser();
    $trip    = $this->openTrip();
    $area    = $this->shippingArea();
    $product = syncProduct($trip, 500000);

    syncOrder($this, $trip, $product, $area, 500000);

    $this->actingAs($admin)->put("/products/{$product->id}", [
        'trip_id'      => $trip->id,
        'supplier_id'  => $product->supplier_id,
        'product_code' => $product->product_code,
        'price'        => 650000,
        'weight_gram'  => $product->weight_gram,
        'status'       => $product->status,
    ])->assertRedirect();

    $log = ActivityLog::where('action', 'product.price_synced')
        ->where('subject_type', 'product')
        ->where('subject_id', $product->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->changes['price']['new'])->toBe(650000.0);
});

test('no activity log is written when product price does not change', function () {
    $admin   = $this->adminUser();
    $trip    = $this->openTrip();
    $product = syncProduct($trip, 500000);

    $this->actingAs($admin)->put("/products/{$product->id}", [
        'trip_id'      => $trip->id,
        'supplier_id'  => $product->supplier_id,
        'product_code' => $product->product_code,
        'price'        => 500000, // same price
        'weight_gram'  => $product->weight_gram,
        'status'       => $product->status,
    ])->assertRedirect();

    expect(ActivityLog::where('action', 'product.price_synced')->count())->toBe(0);
});

// ── Shipping area price sync: shipping fee ────────────────────────────────────

test('updating shipping area price_per_kg recalculates shipping fee on open-trip orders', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $area  = ShippingArea::factory()->create(['price_per_kg' => 25000]);

    // Product weighs 1000g → 1 chargeable kg → fee = 25,000
    $supplier = Supplier::factory()->create();
    $product  = Product::create([
        'trip_id'      => $trip->id,
        'product_code' => 'SHP_01',
        'price'        => 200000,
        'weight_gram'  => 1000,
        'status'       => 'active',
        'supplier_id'  => $supplier->id,
    ]);

    $order = syncOrder($this, $trip, $product, $area, 200000);
    // Manually set shipping fee to match pre-update rate
    $order->update(['shipping_fee' => 25000, 'total_amount' => 225000,
                    'shipping_weight_gram' => 1000, 'shipping_kg_charged' => 1]);

    // Raise price to 40,000/kg
    $this->actingAs($admin)->put("/shipping/{$area->id}", [
        'name'         => $area->name,
        'province'     => $area->province,
        'price_per_kg' => 40000,
        'is_active'    => 1,
    ])->assertRedirect();

    $order->refresh();
    // Shipping fee should now reflect 40,000/kg × 1 kg = 40,000
    expect((float) $order->shipping_fee)->toBe(40000.0);
});

test('shipping price change does NOT touch orders in non-open trips', function () {
    $admin       = $this->adminUser();
    $closedTrip  = Trip::factory()->closed()->create(['created_by' => $admin->id]);
    $area        = ShippingArea::factory()->create(['price_per_kg' => 25000]);
    $supplier    = Supplier::factory()->create();

    $product = Product::create([
        'trip_id'      => $closedTrip->id,
        'product_code' => 'SHP_CL_01',
        'price'        => 200000,
        'weight_gram'  => 1000,
        'status'       => 'active',
        'supplier_id'  => $supplier->id,
    ]);

    $customer = $this->customer($admin);
    $order = Order::factory()->create([
        'trip_id'          => $closedTrip->id,
        'customer_id'      => $customer->id,
        'created_by'       => $admin->id,
        'shipping_area_id' => $area->id,
        'shipping_fee'     => 25000,
        'total_amount'     => 225000,
    ]);

    $this->actingAs($admin)->put("/shipping/{$area->id}", [
        'name'         => $area->name,
        'province'     => $area->province,
        'price_per_kg' => 40000,
        'is_active'    => 1,
    ])->assertRedirect();

    // Closed-trip order shipping fee must be unchanged
    expect((float) $order->fresh()->shipping_fee)->toBe(25000.0);
});

// ── Shipping price sync: payment status downgrade ─────────────────────────────

test('shipping fee increase on a fully-paid order downgrades payment status to partial', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $area  = ShippingArea::factory()->create(['price_per_kg' => 25000]);

    $supplier = Supplier::factory()->create();
    $product  = Product::create([
        'trip_id'      => $trip->id,
        'product_code' => 'SHP_PAY_01',
        'price'        => 200000,
        'weight_gram'  => 1000,
        'status'       => 'active',
        'supplier_id'  => $supplier->id,
    ]);

    $customer = $this->customer($admin);

    // Total = 200,000 product + 25,000 shipping = 225,000; customer paid in full
    $order = Order::factory()->create([
        'trip_id'              => $trip->id,
        'customer_id'          => $customer->id,
        'created_by'           => $admin->id,
        'shipping_area_id'     => $area->id,
        'subtotal'             => 200000,
        'shipping_fee'         => 25000,
        'total_amount'         => 225000,
        'deposit_paid'         => 225000,
        'payment_status'       => 'paid',
        'shipping_weight_gram' => 1000,
        'shipping_kg_charged'  => 1,
    ]);

    OrderItem::create([
        'order_id'   => $order->id,
        'product_id' => $product->id,
        'quantity'   => 1,
        'unit_price' => 200000,
        'line_total' => 200000,
        'status'     => 'confirmed',
    ]);

    Payment::factory()->create([
        'order_id'    => $order->id,
        'recorded_by' => $admin->id,
        'amount'      => 225000,
        'type'        => 'full',
    ]);

    // Raise to 40,000/kg → new total = 240,000; customer paid 225,000 → partial
    $this->actingAs($admin)->put("/shipping/{$area->id}", [
        'name'         => $area->name,
        'province'     => $area->province,
        'price_per_kg' => 40000,
        'is_active'    => 1,
    ])->assertRedirect();

    $order->refresh();
    expect($order->payment_status)->toBe('partial')
        ->and((float) $order->deposit_paid)->toBe(225000.0); // payment log unchanged
});

test('shipping payment records are untouched after shipping price sync', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $area  = ShippingArea::factory()->create(['price_per_kg' => 25000]);

    $supplier = Supplier::factory()->create();
    $product  = Product::create([
        'trip_id'      => $trip->id,
        'product_code' => 'SHP_LOG_01',
        'price'        => 200000,
        'weight_gram'  => 1000,
        'status'       => 'active',
        'supplier_id'  => $supplier->id,
    ]);

    $customer = $this->customer($admin);
    $order = Order::factory()->create([
        'trip_id'          => $trip->id,
        'customer_id'      => $customer->id,
        'created_by'       => $admin->id,
        'shipping_area_id' => $area->id,
        'subtotal'         => 200000,
        'shipping_fee'     => 25000,
        'total_amount'     => 225000,
        'deposit_paid'     => 225000,
        'payment_status'   => 'paid',
    ]);

    $payment = Payment::factory()->create([
        'order_id'    => $order->id,
        'recorded_by' => $admin->id,
        'amount'      => 225000,
        'type'        => 'full',
    ]);

    $this->actingAs($admin)->put("/shipping/{$area->id}", [
        'name'         => $area->name,
        'province'     => $area->province,
        'price_per_kg' => 40000,
        'is_active'    => 1,
    ])->assertRedirect();

    $fresh = $payment->fresh();
    expect((float) $fresh->amount)->toBe(225000.0)
        ->and($fresh->voided_at)->toBeNull();
});

// ── Shipping area price sync: activity log ────────────────────────────────────

test('shipping price sync writes an activity log entry', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $area     = ShippingArea::factory()->create(['price_per_kg' => 25000]);
    $supplier = Supplier::factory()->create();

    $product = Product::create([
        'trip_id'      => $trip->id,
        'product_code' => 'SHP_ACT_01',
        'price'        => 200000,
        'weight_gram'  => 1000,
        'status'       => 'active',
        'supplier_id'  => $supplier->id,
    ]);

    $customer = $this->customer($admin);
    $order = Order::factory()->create([
        'trip_id'          => $trip->id,
        'customer_id'      => $customer->id,
        'created_by'       => $admin->id,
        'shipping_area_id' => $area->id,
    ]);

    OrderItem::create([
        'order_id'   => $order->id,
        'product_id' => $product->id,
        'quantity'   => 1,
        'unit_price' => 200000,
        'line_total' => 200000,
        'status'     => 'confirmed',
    ]);

    $this->actingAs($admin)->put("/shipping/{$area->id}", [
        'name'         => $area->name,
        'province'     => $area->province,
        'price_per_kg' => 40000,
        'is_active'    => 1,
    ])->assertRedirect();

    $log = ActivityLog::where('action', 'shipping.price_synced')
        ->where('subject_type', 'shipping_area')
        ->where('subject_id', $area->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->changes['price_per_kg']['new'])->toBe(40000.0);
});

test('no activity log written when shipping price_per_kg does not change', function () {
    $admin = $this->adminUser();
    $area  = ShippingArea::factory()->create(['price_per_kg' => 25000]);

    $this->actingAs($admin)->put("/shipping/{$area->id}", [
        'name'         => $area->name,
        'province'     => $area->province,
        'price_per_kg' => 25000, // same
        'is_active'    => 1,
    ])->assertRedirect();

    expect(ActivityLog::where('action', 'shipping.price_synced')->count())->toBe(0);
});
