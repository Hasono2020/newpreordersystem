<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\PromoRule;
use App\Models\SalesAdjustment;
use App\Services\CombinedInvoiceService;
use App\Services\PromoService;

/*
 * "Before / after" views of an invoice once a Sales Return or Credit Note has been
 * issued. "Before" = the invoice as if none of the active returns / credit notes had
 * happened: returned items back, refunds off, promo + shipping + totals worked out
 * again on that. It is a reconstruction for DISPLAY — nothing is ever saved.
 *
 * The scenario is chosen so that "before" differs from "after" in every way that
 * matters, including a promo that ONLY the original quantity qualifies for:
 *   3 units × 100,000 with a "3+ items = 5,000 off each" promo → 300,000 − 15,000 = 285,000
 *   fully paid (285,000), then 1 unit returned → 2 units, promo lost → 200,000,
 *   refund 85,000 (the overpayment), then a 7,000 Credit Note → paid 193,000.
 */

function baPay($test, $admin, Order $order, int $amount): Payment
{
    $test->actingAs($admin)->post(route('orders.payments.add', $order), [
        'amount' => $amount, 'type' => 'deposit', 'paid_at' => now()->format('Y-m-d'),
    ])->assertSessionDoesntHaveErrors();
    $payment = Payment::where('order_id', $order->id)->where('type', 'deposit')->latest('id')->first();
    $test->actingAs($admin)->post(route('payments.verify', $payment));
    return $payment;
}

function baPromo($trip): void
{
    PromoRule::create([
        'name' => '3+ items', 'min_items' => 3, 'discount_per_item' => 5000, 'discount_flat' => 0,
        'max_shipping_subsidy' => 0, 'eligible_customer_types' => ['customer'], 'excluded_product_codes' => [],
        'trip_id' => $trip->id, 'is_active' => true,
    ]);
}

function baProduct($trip, int $price = 100000, int $weight = 100): Product
{
    return Product::create([
        'trip_id' => $trip->id, 'product_code' => 'BA' . fake()->unique()->numerify('####'),
        'price' => $price, 'weight_gram' => $weight, 'status' => 'active',
    ]);
}

function baOrder($trip, $customer, $admin, Product $product, int $qty, array $attrs = []): array
{
    $order = Order::factory()->create(array_merge([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
        'shipping_area_id' => null, 'order_number' => null, 'subtotal' => 0, 'total_amount' => 0,
        'discount_amount' => 0, 'shipping_fee' => 0, 'shipping_discount' => 0, 'deposit_paid' => 0, 'payment_status' => 'unpaid',
    ], $attrs));
    $item = OrderItem::create([
        'order_id' => $order->id, 'product_id' => $product->id, 'quantity' => $qty,
        'unit_price' => $product->price, 'line_total' => $product->price * $qty, 'status' => 'pending',
    ]);
    return [$order, $item];
}

/** The scenario in the header comment, fully played out through the real routes. */
function baScenario($test): array
{
    $admin    = $test->adminUser();
    $trip     = $test->openTrip();
    $customer = $test->customer($admin);
    $customer->update(['type' => 'customer', 'default_shipping_area_id' => null]);
    baPromo($trip);

    [$order, $item] = baOrder($trip, $customer, $admin, baProduct($trip), 3);
    app(PromoService::class)->recalcCustomerShipping($customer->id, $trip->id);
    expect((float) $order->fresh()->total_amount)->toBe(285000.0); // sanity: the promo applied to the original

    baPay($test, $admin, $order, 285000);

    $test->actingAs($admin)->post(route('orders.sales-adjustments.store', $order), [
        'type' => 'return', 'items' => [['order_item_id' => $item->id, 'quantity' => 1]],
    ])->assertSessionDoesntHaveErrors();
    $test->actingAs($admin)->post(route('orders.sales-adjustments.store', $order), [
        'type' => 'credit_note', 'amount' => 7000,
    ])->assertSessionDoesntHaveErrors();

    return [$admin, $trip, $customer, $order->fresh(), $item->fresh()];
}

function baCombinedUrl($customer, $trip, array $extra = []): string
{
    return route('orders.combined-invoice', array_merge(['customer' => $customer->id, 'trip_id' => $trip->id], $extra));
}

// ── Sanity: the scenario really produces a different "after" ────────

test('after the return and credit note the stored order is smaller and has paid less', function () {
    [$admin, $trip, $customer, $order, $item] = baScenario($this);

    expect($item->quantity)->toBe(2);
    expect((float) $order->total_amount)->toBe(200000.0);       // promo lost with the 3rd unit
    expect((float) $order->deposit_paid)->toBe(193000.0);        // 285,000 − 85,000 refund − 7,000 credit note
});

// ── Combined invoice ────────────────────────────────────────────────

test('the combined invoice "before" view shows the original items, promo, total and payments', function () {
    [$admin, $trip, $customer] = baScenario($this);

    $this->actingAs($admin)->get(baCombinedUrl($customer, $trip, ['view' => 'original']))
        ->assertOk()
        ->assertSeeText('ORIGINAL INVOICE')
        ->assertSeeText('as this invoice stood before')
        ->assertSeeText('Rp 300.000')            // subtotal at 3 units
        ->assertSeeText('Rp 15.000')             // the promo, which only 3 units earn
        ->assertSeeText('Rp 285.000')            // grand total — and total paid
        ->assertDontSeeText('Rp 85.000')         // the Sales Return's refund isn't in the original payments
        ->assertDontSeeText('Rp 7.000');         // nor the Credit Note's
});

test('the combined invoice "after" view is the current one, labelled adjusted, with a way to switch', function () {
    [$admin, $trip, $customer] = baScenario($this);

    $page = $this->actingAs($admin)->get(baCombinedUrl($customer, $trip))
        ->assertOk()
        ->assertSeeText('COMBINED INVOICE')
        ->assertDontSeeText('ORIGINAL INVOICE')
        ->assertSeeText('ADJUSTED')
        ->assertSeeText('Before (original)')
        ->assertSeeText('Rp 200.000')
        ->assertSeeText('Rp 85.000')             // the refunds are in the payment history
        ->assertSeeText('Rp 7.000');

    expect($page->getContent())->toContain('view=original');                  // the switch links to the original
    foreach (SalesAdjustment::all() as $adj) $page->assertSeeText($adj->adjustment_number);
});

test('the "before" view offers the way back to the current one', function () {
    [$admin, $trip, $customer] = baScenario($this);

    $this->actingAs($admin)->get(baCombinedUrl($customer, $trip, ['view' => 'original']))
        ->assertOk()->assertSeeText('After returns & credits');
});

test('with no active return or credit note there is nothing to compare — no switch, and ?view=original is ignored', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $customer->update(['default_shipping_area_id' => null]);
    baOrder($trip, $customer, $admin, baProduct($trip), 2);

    $this->actingAs($admin)->get(baCombinedUrl($customer, $trip, ['view' => 'original']))
        ->assertOk()
        ->assertSeeText('COMBINED INVOICE')
        ->assertDontSeeText('ORIGINAL INVOICE')
        ->assertDontSeeText('Before (original)')
        ->assertDontSeeText('ADJUSTED');
});

test('once every return and credit note is voided the comparison goes away', function () {
    [$admin, $trip, $customer] = baScenario($this);

    foreach (SalesAdjustment::all() as $adj) {
        $this->actingAs($admin)->post(route('sales-adjustments.void', $adj), ['void_reason' => 'test']);
    }

    $this->actingAs($admin)->get(baCombinedUrl($customer, $trip, ['view' => 'original']))
        ->assertOk()->assertDontSeeText('ORIGINAL INVOICE')->assertDontSeeText('Before (original)');
});

// ── Single-order invoice ────────────────────────────────────────────

test('the single-order invoice "before" view shows the original total and no refund lines', function () {
    [$admin, $trip, $customer, $order] = baScenario($this);

    $this->actingAs($admin)->get(route('orders.invoice', [$order, 'view' => 'original']))
        ->assertOk()
        ->assertSeeText('as this invoice stood before')
        ->assertSeeText('Rp 285.000')
        ->assertSeeText('Rp 15.000')
        ->assertDontSeeText('Rp 85.000')
        ->assertDontSeeText('Rp 7.000');
});

test('the single-order invoice "after" view is the current one and links to the original', function () {
    [$admin, $trip, $customer, $order] = baScenario($this);

    $page = $this->actingAs($admin)->get(route('orders.invoice', $order))
        ->assertOk()->assertSeeText('ADJUSTED')->assertSeeText('Rp 200.000')->assertSeeText('Before (original)');

    expect($page->getContent())->toContain('view=original');
});

// ── Display only: nothing may be saved ──────────────────────────────

test('viewing the original never changes anything in the database', function () {
    [$admin, $trip, $customer, $order, $item] = baScenario($this);

    $snapshot = fn () => [
        'qty'      => OrderItem::find($item->id)->quantity,
        'order'    => Order::find($order->id)->only(['subtotal', 'discount_amount', 'shipping_fee', 'total_amount', 'deposit_paid', 'payment_status']),
        'payments' => Payment::where('order_id', $order->id)->orderBy('id')->get(['id', 'amount', 'type', 'voided_at'])->toArray(),
        'adj'      => SalesAdjustment::count(),
    ];
    $before = $snapshot();

    $this->actingAs($admin)->get(baCombinedUrl($customer, $trip, ['view' => 'original']))->assertOk();
    $this->actingAs($admin)->get(route('orders.invoice', [$order, 'view' => 'original']))->assertOk();

    expect($snapshot())->toEqual($before);
});

test('restoreOriginal rebuilds from fresh copies and leaves the models it was given alone', function () {
    [$admin, $trip, $customer, $order, $item] = baScenario($this);

    $given    = Order::with('items', 'payments')->where('id', $order->id)->get();
    $restored = app(CombinedInvoiceService::class)->restoreOriginal($given, $trip->id)->first();

    expect($restored->items->first()->quantity)->toBe(3);
    expect((float) $restored->total_amount)->toBe(285000.0);
    expect((float) $restored->deposit_paid)->toBe(285000.0);
    expect($restored->payment_status)->toBe('paid');
    expect($restored->payments->where('type', 'refund')->count())->toBe(0);

    // The collection the caller passed in is untouched.
    expect($given->first()->items->first()->quantity)->toBe(2);
    expect((float) $given->first()->deposit_paid)->toBe(193000.0);
});

// ── Shipping that moves when the first order is fully returned ──────

test('the original puts shipping back on the order that carried it, even if it was fully returned', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $area     = $this->shippingArea();
    $customer = $this->customer($admin);
    $customer->update(['type' => 'customer', 'default_shipping_area_id' => null]);
    $product  = baProduct($trip, 100000, 500);

    // A is older and carries the combined shipping; B is newer.
    [$a, $aItem] = baOrder($trip, $customer, $admin, $product, 1, ['shipping_area_id' => $area->id, 'ordered_at' => now()->subHour(), 'created_at' => now()->subHour()]);
    [$b]         = baOrder($trip, $customer, $admin, $product, 2, ['shipping_area_id' => $area->id]);
    app(PromoService::class)->recalcCustomerShipping($customer->id, $trip->id);

    $feeOnA = (float) $a->fresh()->shipping_fee;
    expect($feeOnA)->toBeGreaterThan(0);
    expect((float) $b->fresh()->shipping_fee)->toBe(0.0);

    // A is returned in full — shipping moves to B, A now shows none.
    $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $a), [
        'type' => 'return', 'items' => [['order_item_id' => $aItem->id, 'quantity' => 1]],
    ])->assertSessionDoesntHaveErrors();
    expect((float) $a->fresh()->shipping_fee)->toBe(0.0);
    expect((float) $b->fresh()->shipping_fee)->toBeGreaterThan(0);

    $group    = Order::where('customer_id', $customer->id)->where('trip_id', $trip->id)->get();
    $restored = app(CombinedInvoiceService::class)->restoreOriginal($group, $trip->id)->keyBy('id');

    expect((float) $restored[$a->id]->shipping_fee)->toBe($feeOnA);   // A carried it originally
    expect((float) $restored[$b->id]->shipping_fee)->toBe(0.0);        // B did not
});

// ── The shared maths: the refactor must not change what is stored ───

test('the pure calculation gives exactly what recalculation stores, for every order of a customer', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $area     = $this->shippingArea();
    $customer = $this->customer($admin);
    $customer->update(['type' => 'customer', 'default_shipping_area_id' => null]);
    baPromo($trip);
    $product  = baProduct($trip, 100000, 400);

    baOrder($trip, $customer, $admin, $product, 2, ['shipping_area_id' => $area->id, 'ordered_at' => now()->subHour(), 'created_at' => now()->subHour()]);
    baOrder($trip, $customer, $admin, $product, 2, ['shipping_area_id' => $area->id]);

    app(PromoService::class)->recalcCustomerShipping($customer->id, $trip->id);

    $orders = Order::with('items.product', 'items.variant', 'customer', 'shippingArea')
        ->where('customer_id', $customer->id)->where('trip_id', $trip->id)
        ->orderByRaw('COALESCE(ordered_at, created_at) ASC')->orderBy('id')->get();

    $allocation = app(PromoService::class)->allocateCustomerTotals($orders, $trip->id);

    expect($allocation)->toHaveCount(2);
    foreach ($orders as $o) {
        foreach ($allocation[$o->id] as $column => $value) {
            expect((float) $o->fresh()->{$column})->toBe((float) $value);
        }
    }
    // And it really is the combined promo: 4 units reach the 3+ threshold even though neither order alone does.
    expect((float) array_sum(array_column($allocation, 'discount_amount')))->toBe(20000.0);
});

// ── Entry points ────────────────────────────────────────────────────

test('the order page links to the before and after invoice once there is an adjustment', function () {
    [$admin, $trip, $customer, $order] = baScenario($this);

    $html = $this->actingAs($admin)->get(route('orders.show', $order))->assertOk()->getContent();
    expect($html)->toContain('view=original');
});

test('the order page has no before/after link when there is nothing to compare', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    [$order]  = baOrder($trip, $customer, $admin, baProduct($trip), 1);

    $html = $this->actingAs($admin)->get(route('orders.show', $order))->assertOk()->getContent();
    expect($html)->not->toContain('view=original');
});

test('the return / credit note page offers both invoices, for the order and for the combined invoice', function () {
    [$admin, $trip, $customer, $order] = baScenario($this);
    $adj = SalesAdjustment::where('type', 'return')->first();

    $html = $this->actingAs($admin)->get(route('sales-adjustments.show', $adj))->assertOk()->getContent();

    // In HTML source the "&" between two query parameters is written "&amp;", so compare against
    // the escaped form of each link (e() is what Blade itself applies).
    expect($html)->toContain(e(route('orders.invoice', [$order, 'view' => 'original'])));
    expect($html)->toContain(e(route('orders.combined-invoice', ['customer' => $customer->id, 'trip_id' => $trip->id, 'view' => 'original'])));
});

// ── Back button ─────────────────────────────────────────────────────

test('switching between before and after replaces the history entry, so Back still leaves the invoice', function () {
    [$admin, $trip, $customer, $order] = baScenario($this);

    // Both views, both pages: each of the two switch links must replace the page rather than stack a new one.
    $pages = [
        baCombinedUrl($customer, $trip),
        baCombinedUrl($customer, $trip, ['view' => 'original']),
        route('orders.invoice', $order),
        route('orders.invoice', [$order, 'view' => 'original']),
    ];
    foreach ($pages as $url) {
        $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();
        expect(substr_count($html, 'window.location.replace(this.href)'))->toBe(2);
    }

    // The combined invoice's Back button still steps back through history (to wherever the person came from).
    $combined = $this->actingAs($admin)->get(baCombinedUrl($customer, $trip, ['view' => 'original']))->getContent();
    expect($combined)->toContain('window.history.back()');
});
