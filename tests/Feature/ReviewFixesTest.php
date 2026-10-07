<?php

use App\Http\Controllers\TripInvoicePdfController;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesAdjustment;
use App\Models\SalesAdjustmentItem;
use App\Services\CombinedInvoiceService;

/*
 * Fixes from the code review:
 *  1. Deleting an order tore a credit move in half and left the other order with
 *     credit that had nothing behind it (or credit that vanished).
 *  2. The Excel "Final Payment" columns could pick a refund or an internal move.
 *  3. A Sales Return listing the same item twice double-counted it.
 *  4. A double-click issued a refund twice.
 *  5/6. The PDF download could lower a higher memory limit, and counted customers
 *       only after building every invoice.
 *  7. The word "reallocation" is reserved for the system's own credit moves.
 *  8. An overpaid customer saw a red negative "Balance Due" instead of credit.
 */

class RfExportReader
{
    use \App\Traits\HandlesXlsx;

    public function read(string $path): array { return $this->readXlsx($path); }
}

function rfCustomer($test, $admin, ?string $name = null)
{
    $customer = $test->customer($admin);
    $customer->update(['default_shipping_area_id' => null] + ($name ? ['name' => $name] : []));
    return $customer->fresh();
}

/** An order with one unit of each price in $prices — so its real total is their sum. */
function rfOrder($trip, $customer, $admin, array $prices, array $attrs = []): Order
{
    $sum   = array_sum($prices);
    $order = Order::factory()->create(array_merge([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
        'shipping_area_id' => null, 'order_number' => null,
        'subtotal' => $sum, 'total_amount' => $sum, 'discount_amount' => 0,
        'shipping_fee' => 0, 'shipping_discount' => 0, 'deposit_paid' => 0, 'payment_status' => 'unpaid',
    ], $attrs));

    foreach ($prices as $price) {
        $product = Product::create([
            'trip_id' => $trip->id, 'product_code' => 'RF' . fake()->unique()->numerify('######'),
            'price' => $price, 'weight_gram' => 100, 'status' => 'active',
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 1,
            'unit_price' => $price, 'line_total' => $price, 'status' => 'pending',
        ]);
    }
    return $order->fresh();
}

/** Record a payment through the real route, then verify it (only verified money can move or be refunded). */
function rfPay($test, $admin, Order $order, int $amount, bool $verify = true): Payment
{
    $test->actingAs($admin)->post(route('orders.payments.add', $order), [
        'amount' => $amount, 'type' => 'deposit', 'paid_at' => now()->format('Y-m-d'),
    ])->assertSessionDoesntHaveErrors();

    $payment = Payment::where('order_id', $order->id)->where('type', 'deposit')->latest('id')->first();
    if ($verify) $test->actingAs($admin)->post(route('payments.verify', $payment));
    return $payment;
}

/** A customer with a big-total order (older) and a small-total one, money on the small one: credit moves across. */
function rfTwoOrdersWithMovedCredit($test): array
{
    $admin    = $test->adminUser();
    $trip     = $test->openTrip();
    $customer = rfCustomer($test, $admin);

    $big   = rfOrder($trip, $customer, $admin, [50000, 50000, 50000], ['ordered_at' => now()->subHour(), 'created_at' => now()->subHour()]); // owes 150,000
    $small = rfOrder($trip, $customer, $admin, [30000]);                                                                                      // owes  30,000

    rfPay($test, $admin, $big, 20000);
    rfPay($test, $admin, $small, 180000); // 130,000 of this moves onto $big

    return [$admin, $trip, $customer, $big->fresh(), $small->fresh()];
}

// ── 1. Deleting an order must not leave half a credit move behind ────

test('deleting the order that SENT credit takes the credit it gave away with it', function () {
    [$admin, $trip, $customer, $big, $small] = rfTwoOrdersWithMovedCredit($this);
    expect($big->payment_status)->toBe('paid');                       // sanity: the move happened
    expect((float) $big->deposit_paid)->toBe(150000.0);

    $this->actingAs($admin)->delete(route('orders.destroy', $small))->assertRedirect();

    // The 180,000 that funded the move is gone, so $big is back to its own 20,000.
    expect($big->fresh()->payment_status)->toBe('partial');
    expect((float) $big->fresh()->deposit_paid)->toBe(20000.0);
    expect(Payment::where('method', 'reallocation')->whereNull('voided_at')->count())->toBe(0);
    expect(ActivityLog::where('action', 'payment.reallocation_reversed')->count())->toBeGreaterThanOrEqual(1);
});

test('deleting the order that RECEIVED credit gives the sender its money back', function () {
    [$admin, $trip, $customer, $big, $small] = rfTwoOrdersWithMovedCredit($this);
    expect((float) $small->deposit_paid)->toBe(50000.0);              // 180,000 less the 130,000 it sent

    $this->actingAs($admin)->delete(route('orders.destroy', $big))->assertRedirect();

    // Without this the 130,000 would stay "sent" to an order that no longer exists —
    // vanished from the customer's real balance and unrefundable.
    expect((float) $small->fresh()->deposit_paid)->toBe(180000.0);
    expect(Payment::where('method', 'reallocation')->whereNull('voided_at')->count())->toBe(0);
});

test('a healthy credit move is left alone when an unrelated order is deleted', function () {
    [$admin, $trip, $customer, $big, $small] = rfTwoOrdersWithMovedCredit($this);
    $unrelated = rfOrder($trip, rfCustomer($this, $admin), $admin, [10000]); // a different customer

    $this->actingAs($admin)->delete(route('orders.destroy', $unrelated))->assertRedirect();

    expect($big->fresh()->payment_status)->toBe('paid');
    expect(Payment::where('method', 'reallocation')->whereNull('voided_at')->count())->toBe(2); // both halves still active
});

// ── 2. "Final Payment" in the Excel export means a real payment ──────

test('the export\'s Final Payment and TGL DP ignore refunds and internal credit moves', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = rfCustomer($this, $admin);
    $order    = rfOrder($trip, $customer, $admin, [500000]);

    $mk = fn (array $a) => Payment::factory()->create(array_merge(
        ['order_id' => $order->id, 'voided_at' => null, 'verification_status' => 'verified'], $a));
    $mk(['amount' => 100000, 'type' => 'deposit', 'paid_at' => now()->subDays(3)]);                         // first real payment
    $mk(['amount' => 50000,  'type' => 'partial', 'paid_at' => now()->subDays(2)]);                         // LAST real payment
    $mk(['amount' => 20000,  'type' => 'refund',  'method' => 'Refund', 'paid_at' => now()->subDay()]);     // a Credit Note's refund — later, but not a payment
    $mk(['amount' => 30000,  'type' => 'partial', 'method' => 'reallocation', 'paid_at' => now()]);          // an internal move — latest of all, but not a payment

    $path = (new RfExportReader())->read(
        $this->actingAs($admin)->get(route('orders.export', ['trip_id' => $trip->id]))->baseResponse->getFile()->getPathname()
    );
    $header = $path[0];
    $row    = $path[1];
    $col    = fn ($name) => array_search($name, $header);

    expect($row[$col('TGL DP')])->toBe(now()->subDays(3)->format('d-M-y'));
    expect($row[$col('FINAL PAYMENT DATE')])->toBe(now()->subDays(2)->format('d-M-y'));
    expect((float) $row[$col('FINAL PAYMENT AMOUNT')])->toBe(50000.0);
});

test('two payments on the same day: the later one recorded is the final payment', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $order    = rfOrder($trip, rfCustomer($this, $admin), $admin, [500000]);

    $day = now()->subDays(2);
    Payment::factory()->create(['order_id' => $order->id, 'amount' => 1000, 'type' => 'deposit', 'paid_at' => $day, 'voided_at' => null, 'verification_status' => 'verified']);
    Payment::factory()->create(['order_id' => $order->id, 'amount' => 2000, 'type' => 'partial', 'paid_at' => $day, 'voided_at' => null, 'verification_status' => 'verified']);

    $rows   = (new RfExportReader())->read(
        $this->actingAs($admin)->get(route('orders.export', ['trip_id' => $trip->id]))->baseResponse->getFile()->getPathname()
    );
    $amount = (float) $rows[1][array_search('FINAL PAYMENT AMOUNT', $rows[0])];

    expect($amount)->toBe(2000.0); // not left to whichever row the database happened to return last
});

// ── 3. A Sales Return naming the same item twice ─────────────────────

test('the same item listed twice in one Sales Return is added up, not counted twice', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $order = rfOrder($trip, rfCustomer($this, $admin), $admin, [100000]);
    $item  = $order->items->first();
    $item->update(['quantity' => 3, 'line_total' => 300000]);

    $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $order), [
        'type'  => 'return',
        'items' => [
            ['order_item_id' => $item->id, 'quantity' => 1],
            ['order_item_id' => $item->id, 'quantity' => 1],
        ],
    ])->assertSessionDoesntHaveErrors();

    $adjustment = SalesAdjustment::first();
    expect($item->fresh()->quantity)->toBe(1);                                              // 3 - (1 + 1)
    expect((float) $adjustment->amount)->toBe(200000.0);                                    // 2 units, once
    expect(SalesAdjustmentItem::where('sales_adjustment_id', $adjustment->id)->count())->toBe(1);
    expect(SalesAdjustmentItem::first()->quantity)->toBe(2);

    // And voiding puts back exactly what was taken — not double.
    $this->actingAs($admin)->post(route('sales-adjustments.void', $adjustment));
    expect($item->fresh()->quantity)->toBe(3);
});

test('the same item listed twice that together exceed what is on the order is refused', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $order = rfOrder($trip, rfCustomer($this, $admin), $admin, [100000]);
    $item  = $order->items->first();
    $item->update(['quantity' => 3, 'line_total' => 300000]);

    // Each line alone (2 of 3) looks fine; together they ask for 4 of 3.
    $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $order), [
        'type'  => 'return',
        'items' => [
            ['order_item_id' => $item->id, 'quantity' => 2],
            ['order_item_id' => $item->id, 'quantity' => 2],
        ],
    ])->assertStatus(422);

    expect($item->fresh()->quantity)->toBe(3);
    expect(SalesAdjustment::count())->toBe(0);
});

// ── 4. Refunds can't be issued twice by a double-click ───────────────

test('a Credit Note submitted twice with the same token is issued once', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $order = rfOrder($trip, rfCustomer($this, $admin), $admin, [100000, 100000, 100000]);
    rfPay($this, $admin, $order, 300000);

    $post = fn () => $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $order), [
        'type' => 'credit_note', 'amount' => 50000, 'client_token' => 'same-page-load-token',
    ]);

    $post()->assertSessionDoesntHaveErrors();
    $second = $post();

    $second->assertSessionHas('error');
    expect(session('error'))->toContain('already issued');
    expect(SalesAdjustment::count())->toBe(1);
    expect(Payment::where('order_id', $order->id)->where('type', 'refund')->count())->toBe(1);
    expect((float) $order->fresh()->deposit_paid)->toBe(250000.0);                 // refunded once, not twice
    expect(ActivityLog::where('action', 'sales_adjustment.duplicate_blocked')->count())->toBe(1);
});

test('a Sales Return submitted twice with the same token reduces the order once', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $order = rfOrder($trip, rfCustomer($this, $admin), $admin, [100000]);
    $item  = $order->items->first();
    $item->update(['quantity' => 3, 'line_total' => 300000]);

    $post = fn () => $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $order), [
        'type' => 'return', 'items' => [['order_item_id' => $item->id, 'quantity' => 1]], 'client_token' => 'return-token',
    ]);

    $post();
    $post();

    expect($item->fresh()->quantity)->toBe(2);          // 3 - 1, once
    expect(SalesAdjustment::count())->toBe(1);
});

test('different tokens are different submissions — two real Credit Notes both go through', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $order = rfOrder($trip, rfCustomer($this, $admin), $admin, [100000, 100000, 100000]);
    rfPay($this, $admin, $order, 300000);

    foreach (['token-a', 'token-b'] as $token) {
        $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $order), [
            'type' => 'credit_note', 'amount' => 10000, 'client_token' => $token,
        ])->assertSessionDoesntHaveErrors();
    }

    expect(SalesAdjustment::count())->toBe(2);
});

test('a refused attempt does not use up its token — fixing the amount and retrying works', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $order = rfOrder($trip, rfCustomer($this, $admin), $admin, [100000]);
    rfPay($this, $admin, $order, 100000);

    $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $order), [
        'type' => 'credit_note', 'amount' => 999999, 'client_token' => 'retry-token', // more than is verified
    ])->assertSessionHas('error');
    expect(SalesAdjustment::count())->toBe(0);

    $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $order), [
        'type' => 'credit_note', 'amount' => 40000, 'client_token' => 'retry-token',
    ])->assertSessionDoesntHaveErrors();
    expect(SalesAdjustment::count())->toBe(1);
});

test('requests without a token still work, and both forms on the order page carry one', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $order = rfOrder($trip, rfCustomer($this, $admin), $admin, [100000]);
    rfPay($this, $admin, $order, 100000);

    $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $order), [
        'type' => 'credit_note', 'amount' => 10000,
    ])->assertSessionDoesntHaveErrors();
    expect(SalesAdjustment::count())->toBe(1);

    $html = $this->actingAs($admin)->get(route('orders.show', $order))->assertOk()->getContent();
    expect(substr_count($html, 'name="client_token"'))->toBeGreaterThanOrEqual(2); // Sales Return + Credit Note forms
});

// ── 5 & 6. The trip PDF download ─────────────────────────────────────

test('PHP memory_limit strings are read correctly', function () {
    expect(TripInvoicePdfController::iniBytes('512M'))->toBe(536870912);
    expect(TripInvoicePdfController::iniBytes('1G'))->toBe(1073741824);
    expect(TripInvoicePdfController::iniBytes('256K'))->toBe(262144);
    expect(TripInvoicePdfController::iniBytes('134217728'))->toBe(134217728);
    expect(TripInvoicePdfController::iniBytes(' 64m '))->toBe(67108864);
    expect(TripInvoicePdfController::iniBytes('-1'))->toBe(-1);   // unlimited — must never be "raised" to 512M
    expect(TripInvoicePdfController::iniBytes(''))->toBe(0);
});

test('a trip that is too big is handed to the background build BEFORE any invoice is built', function () {
    config(['invoices.pdf_customers_per_file' => 2, 'invoices.pdf_max_customers' => 3]);

    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    foreach (['A', 'B', 'C', 'D'] as $name) {
        rfOrder($trip, rfCustomer($this, $admin, $name), $admin, [10000]);
    }

    // If the controller built the invoices first (the old behaviour) this expectation fails.
    $this->mock(CombinedInvoiceService::class, fn ($mock) => $mock->shouldNotReceive('forTrip'));

    $response = $this->actingAs($admin)->from(route('orders.index'))->get(route('trips.invoices.pdf', $trip));

    $response->assertRedirect(route('invoice-exports.index', ['trip' => $trip->id]));
    expect(session('warning'))->toContain('4 customers');
})->skip(fn () => ! class_exists(\Dompdf\Dompdf::class), 'dompdf is not installed (composer require dompdf/dompdf)');

test('an empty trip is also refused before anything is built', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();

    $this->mock(CombinedInvoiceService::class, fn ($mock) => $mock->shouldNotReceive('forTrip'));

    $this->actingAs($admin)->from(route('orders.index'))->get(route('trips.invoices.pdf', $trip))
        ->assertRedirect(route('orders.index'))->assertSessionHas('error');
})->skip(fn () => ! class_exists(\Dompdf\Dompdf::class), 'dompdf is not installed (composer require dompdf/dompdf)');

// ── 7. "reallocation" is the system's own word ───────────────────────

test('a payment cannot be recorded with the reserved method name "reallocation"', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $order = rfOrder($trip, rfCustomer($this, $admin), $admin, [100000]);

    $this->actingAs($admin)->post(route('orders.payments.add', $order), [
        'amount' => 50000, 'type' => 'deposit', 'method' => 'reallocation', 'paid_at' => now()->format('Y-m-d'),
    ])->assertSessionHasErrors('method');
    expect(Payment::where('order_id', $order->id)->count())->toBe(0);

    $this->actingAs($admin)->post(route('orders.payments.add', $order), [
        'amount' => 50000, 'type' => 'deposit', 'method' => 'Cash', 'paid_at' => now()->format('Y-m-d'),
    ])->assertSessionDoesntHaveErrors();
    expect(Payment::where('order_id', $order->id)->count())->toBe(1);
});

// ── 8. An overpaid customer sees credit, not a red negative debt ─────

test('the on-screen combined invoice shows "Overpaid (credit)" instead of a negative Balance Due', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = rfCustomer($this, $admin);
    $order    = rfOrder($trip, $customer, $admin, [100000]);
    rfPay($this, $admin, $order, 135000); // 35,000 over

    $page = $this->actingAs($admin)->get(route('orders.combined-invoice', ['customer' => $customer->id, 'trip_id' => $trip->id]));

    $page->assertOk()->assertSeeText('Overpaid (credit)')->assertSeeText('Rp 35.000')->assertDontSeeText('Balance Due')->assertDontSeeText('Rp -35.000');
});

test('the single-order invoice does the same', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $order = rfOrder($trip, rfCustomer($this, $admin), $admin, [100000]);
    rfPay($this, $admin, $order, 135000);

    $this->actingAs($admin)->get(route('orders.invoice', $order))
        ->assertOk()->assertSeeText('Overpaid (credit)')->assertDontSeeText('Rp -35.000');
});

test('the trip PDF page does the same', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = rfCustomer($this, $admin);
    $order    = rfOrder($trip, $customer, $admin, [100000]);
    rfPay($this, $admin, $order, 135000);

    $invoices = app(CombinedInvoiceService::class)->forTrip($trip)->all();
    $html = view('orders.trip-invoices-pdf', [
        'invoices' => $invoices, 'tripName' => $trip->name, 'storeName' => 'Store', 'storeTagline' => '', 'storePhone' => '', 'printedAt' => 'now',
    ])->render();

    expect($html)->toContain('Overpaid (credit)')->not->toContain('Balance Due');
});

test('a customer who still owes money, or is exactly settled, still sees Balance Due', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = rfCustomer($this, $admin);
    $order    = rfOrder($trip, $customer, $admin, [100000]);
    rfPay($this, $admin, $order, 60000); // 40,000 still owed

    $this->actingAs($admin)->get(route('orders.combined-invoice', ['customer' => $customer->id, 'trip_id' => $trip->id]))
        ->assertOk()->assertSeeText('Balance Due')->assertSeeText('Rp 40.000')->assertDontSeeText('Overpaid (credit)');
});
