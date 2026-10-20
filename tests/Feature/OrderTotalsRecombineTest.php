<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\ShippingArea;
use App\Models\Trip;
use App\Models\User;
use App\Services\PromoService;

/*
 * Reported: a customer whose invoice said Grand Total 1.000.000 had four orders whose saved totals
 * added up to 1.078.000, so she looked unpaid and never reached the Shipment tab.
 *
 * Cause: confirming (or correcting) a purchase order's arrival re-priced each affected order ON ITS
 * OWN, as if it were the customer's only order — so every touched order got its own shipping charge,
 * where the invoice (always worked out combined) charges shipping once, on the oldest order.
 * Fix: arrival now re-prices the whole customer (recalcCustomerShipping), like every other path.
 *
 * Also covered: `php artisan orders:check-totals` — a read-only list of customers whose saved totals
 * differ from their invoice, with --fix to recalculate exactly those.
 */

/** A customer with two orders (A older, B newer) in one trip, each with one light item, one shipping area. */
function rcScenario($test): array
{
    $admin = $test->adminUser();
    $trip  = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $area  = ShippingArea::factory()->create(['price_per_kg' => 39000, 'flat_fee' => null]);
    $customer = $test->customer($admin);

    $p1 = Product::create(['trip_id' => $trip->id, 'product_code' => 'RCA01', 'price' => 100000, 'weight_gram' => 300, 'status' => 'active']);
    $p2 = Product::create(['trip_id' => $trip->id, 'product_code' => 'RCB02', 'price' => 50000,  'weight_gram' => 300, 'status' => 'active']);

    $make = function (Product $p, int $qty, $when) use ($trip, $customer, $admin, $area) {
        $order = Order::factory()->create([
            'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
            'shipping_area_id' => $area->id, 'ordered_at' => $when,
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $p->id, 'quantity' => $qty,
            'unit_price' => $p->price, 'line_total' => $p->price * $qty, 'status' => 'pending',
        ]);
        return $order;
    };
    $orderA = $make($p1, 1, now()->subDays(2));
    $orderB = $make($p2, 2, now()->subDay());

    // The normal state: shipping once, on the oldest order.
    app(PromoService::class)->recalcCustomerShipping($customer->id, $trip->id);

    return compact('admin', 'trip', 'customer', 'orderA', 'orderB', 'p1', 'p2', 'area');
}

/** What each of the customer's orders should hold, worked out the way the invoice does it. */
function rcExpected(Trip $trip, Customer $customer): array
{
    $orders = Order::with('items.product', 'items.variant', 'customer', 'shippingArea')
        ->where('trip_id', $trip->id)->where('customer_id', $customer->id)
        ->orderByRaw('COALESCE(ordered_at, created_at) ASC')->orderBy('id')->get();
    return app(PromoService::class)->allocateCustomerTotals($orders, $trip->id);
}

function rcAssertCombined(Trip $trip, Customer $customer): void
{
    foreach (rcExpected($trip, $customer) as $orderId => $cols) {
        $order = Order::find($orderId);
        expect((float) $order->total_amount)->toBe((float) $cols['total_amount'])
            ->and((float) $order->shipping_fee)->toBe((float) $cols['shipping_fee']);
    }
}

/** A purchase order for product $p with $ordered pieces on it, not yet arrived. */
function rcPurchaseOrder(User $admin, Trip $trip, Product $p, int $ordered): array
{
    $po = PurchaseOrder::create(['trip_id' => $trip->id, 'status' => 'draft', 'created_by' => $admin->id]);
    $poItem = PurchaseOrderItem::create([
        'purchase_order_id' => $po->id, 'product_id' => $p->id,
        'quantity_ordered' => $ordered, 'quantity_received' => 0, 'unit_cost' => 40000,
    ]);
    return [$po, $poItem];
}

// ── Arrival no longer gives every touched order its own shipping ─────

test('the scenario starts combined: shipping only on the oldest order', function () {
    $s = rcScenario($this);

    expect((float) $s['orderA']->fresh()->shipping_fee)->toBeGreaterThan(0.0)
        ->and((float) $s['orderB']->fresh()->shipping_fee)->toBe(0.0);
});

test('confirming an arrival that short-ships one order keeps shipping on the oldest order only', function () {
    $s = rcScenario($this);
    [$po, $poItem] = rcPurchaseOrder($s['admin'], $s['trip'], $s['p2'], 2);

    // order B asked for 2, only 1 arrives -> B is split into arrived + sold_out and re-priced
    $this->actingAs($s['admin'])->post(route('purchasing.arrival', $po), [
        'items' => [['id' => $poItem->id, 'quantity_received' => 1]],
    ])->assertRedirect();

    expect((float) $s['orderB']->fresh()->shipping_fee)->toBe(0.0)   // used to become its own full shipping charge
        ->and((float) $s['orderA']->fresh()->shipping_fee)->toBeGreaterThan(0.0);
    rcAssertCombined($s['trip'], $s['customer']);
});

test('the saved totals add up to the invoice total after an arrival', function () {
    $s = rcScenario($this);
    [$po, $poItem] = rcPurchaseOrder($s['admin'], $s['trip'], $s['p2'], 2);

    $this->actingAs($s['admin'])->post(route('purchasing.arrival', $po), [
        'items' => [['id' => $poItem->id, 'quantity_received' => 1]],
    ]);

    $expected = collect(rcExpected($s['trip'], $s['customer']))->sum('total_amount');
    $saved    = Order::where('customer_id', $s['customer']->id)->where('trip_id', $s['trip']->id)->sum('total_amount');
    expect((float) $saved)->toBe((float) $expected);
});

test('correcting a received quantity also re-prices the customer combined', function () {
    $s = rcScenario($this);
    [$po, $poItem] = rcPurchaseOrder($s['admin'], $s['trip'], $s['p2'], 2);

    $this->actingAs($s['admin'])->post(route('purchasing.arrival', $po), ['items' => [['id' => $poItem->id, 'quantity_received' => 1]]]);
    $this->actingAs($s['admin'])->post(route('purchasing.arrival', $po), ['items' => [['id' => $poItem->id, 'quantity_received' => 2]]]);

    expect((float) $s['orderB']->fresh()->shipping_fee)->toBe(0.0);
    rcAssertCombined($s['trip'], $s['customer']);
});

// ── orders:check-totals ─────────────────────────────────────────────

/** Put a customer back in the state the bug left: order B carrying its own shipping. */
function rcCorrupt(array $s): void
{
    $b = $s['orderB']->fresh();
    $b->update(['shipping_fee' => 39000, 'total_amount' => $b->total_amount + 39000]);
}

test('the check lists a customer whose saved totals differ from the invoice, and changes nothing', function () {
    $s = rcScenario($this);
    rcCorrupt($s);
    $before = Order::where('customer_id', $s['customer']->id)->orderBy('id')->pluck('total_amount')->all();

    $this->artisan('orders:check-totals', ['--trip' => $s['trip']->id])
        ->expectsOutputToContain('1 customer(s) have saved totals that differ')
        ->expectsOutputToContain($s['customer']->name)
        ->expectsOutputToContain('Nothing was changed')
        ->assertSuccessful();

    expect(Order::where('customer_id', $s['customer']->id)->orderBy('id')->pluck('total_amount')->all())->toBe($before);
});

test('--fix recalculates the listed customers, and a second check finds nothing', function () {
    $s = rcScenario($this);
    rcCorrupt($s);

    $this->artisan('orders:check-totals', ['--trip' => $s['trip']->id, '--fix' => true])->assertSuccessful();

    expect((float) $s['orderB']->fresh()->shipping_fee)->toBe(0.0);
    rcAssertCombined($s['trip'], $s['customer']);

    $this->artisan('orders:check-totals', ['--trip' => $s['trip']->id])
        ->expectsOutputToContain('Nothing to fix')
        ->assertSuccessful();
});

test('a customer whose totals already match is not listed', function () {
    $s = rcScenario($this);

    $this->artisan('orders:check-totals', ['--trip' => $s['trip']->id])
        ->expectsOutputToContain('Every saved total matches')
        ->assertSuccessful();
});

test('--fix touches only the customers it listed', function () {
    $s = rcScenario($this);
    rcCorrupt($s);

    // a second, healthy customer in the same trip
    $other = $this->customer($s['admin']);
    $order = Order::factory()->create([
        'trip_id' => $s['trip']->id, 'customer_id' => $other->id, 'created_by' => $s['admin']->id,
        'shipping_area_id' => $s['area']->id, 'ordered_at' => now(),
    ]);
    OrderItem::create(['order_id' => $order->id, 'product_id' => $s['p1']->id, 'quantity' => 1,
        'unit_price' => 100000, 'line_total' => 100000, 'status' => 'pending']);
    app(PromoService::class)->recalcCustomerShipping($other->id, $s['trip']->id);
    $otherBefore = $order->fresh()->updated_at;

    $this->travel(5)->minutes();
    $this->artisan('orders:check-totals', ['--trip' => $s['trip']->id, '--fix' => true])->assertSuccessful();

    expect($order->fresh()->updated_at->equalTo($otherBefore))->toBeTrue();
});

test('closed trips are skipped unless asked for', function () {
    $s = rcScenario($this);
    rcCorrupt($s);
    $s['trip']->update(['status' => 'closed']);

    $this->artisan('orders:check-totals')->expectsOutputToContain('No matching trip')->assertFailed();
    $this->artisan('orders:check-totals', ['--include-closed' => true])->expectsOutputToContain('1 customer(s)')->assertSuccessful();
    $this->artisan('orders:check-totals', ['--trip' => $s['trip']->id])->expectsOutputToContain('1 customer(s)')->assertSuccessful();
});
