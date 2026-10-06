<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesAdjustment;
use App\Services\CombinedInvoiceService;

/*
 * A Credit Note or Sales Return line in an invoice's Payment History now carries
 * its document number (CR/B4/10/000007, RP/B4/10/000007), so a customer — or
 * staff comparing against the Returns & Credits page — can tell which document a
 * refund belongs to. Previously the combined invoice and the trip PDF said only
 * "Credit Note" / "Sales Return".
 */

/** One order, fully paid, then a Sales Return and a Credit Note issued against it. */
function numberedInvoiceScenario($test): array
{
    $admin = $test->adminUser();
    $trip  = $test->openTrip();
    $trip->update(['batch_number' => 4]); // so numbers read RP/B4/<month>/000001 rather than random

    $customer = $test->customer($admin);
    $customer->update(['default_shipping_area_id' => null]);

    $product = Product::create(['trip_id' => $trip->id, 'product_code' => 'NUM01', 'price' => 100000, 'weight_gram' => 100, 'status' => 'active']);
    $order = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
        'shipping_area_id' => null, 'order_number' => null,
    ]);
    $item = OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 3, 'unit_price' => 100000, 'line_total' => 300000, 'status' => 'pending']);

    Payment::factory()->create(['order_id' => $order->id, 'amount' => 300000, 'type' => 'deposit', 'paid_at' => now(), 'voided_at' => null, 'verification_status' => 'verified']);
    $order->recalcPaymentStatus();

    // Return 1 unit (100,000 refunded), then refund 50,000 more as a Credit Note.
    $test->actingAs($admin)->post(route('orders.sales-adjustments.store', $order), [
        'type' => 'return', 'items' => [['order_item_id' => $item->id, 'quantity' => 1]],
    ])->assertSessionDoesntHaveErrors();
    $test->actingAs($admin)->post(route('orders.sales-adjustments.store', $order), [
        'type' => 'credit_note', 'amount' => 50000,
    ])->assertSessionDoesntHaveErrors();

    return [
        $admin, $trip, $customer, $order,
        SalesAdjustment::where('type', 'return')->firstOrFail(),
        SalesAdjustment::where('type', 'credit_note')->firstOrFail(),
    ];
}

test('the combined invoice shows the Credit Note and Sales Return numbers', function () {
    [$admin, $trip, $customer, $order, $return, $credit] = numberedInvoiceScenario($this);

    expect($credit->adjustment_number)->toStartWith('CR/B4/');
    expect($return->adjustment_number)->toStartWith('RP/B4/');

    $this->actingAs($admin)
        ->get(route('orders.combined-invoice', ['customer' => $customer->id, 'trip_id' => $trip->id]))
        ->assertOk()
        ->assertSeeText($credit->adjustment_number)
        ->assertSeeText($return->adjustment_number);
});

test('the single-order invoice shows both numbers too', function () {
    [$admin, $trip, $customer, $order, $return, $credit] = numberedInvoiceScenario($this);

    $this->actingAs($admin)->get(route('orders.invoice', $order))
        ->assertOk()
        ->assertSeeText($credit->adjustment_number)
        ->assertSeeText($return->adjustment_number);
});

test('the trip PDF data carries the numbers, and an ordinary payment carries none', function () {
    [$admin, $trip, $customer, $order, $return, $credit] = numberedInvoiceScenario($this);

    $payments = app(CombinedInvoiceService::class)->forTrip($trip)->first()['payments'];
    $byLabel  = collect($payments)->keyBy('label');

    expect($byLabel['Credit Note']['number'])->toBe($credit->adjustment_number);
    expect($byLabel['Sales Return']['number'])->toBe($return->adjustment_number);
    expect($byLabel['Deposit']['number'])->toBeNull();
});

test('a voided Credit Note drops off the invoice, number and all, while the Sales Return stays', function () {
    [$admin, $trip, $customer, $order, $return, $credit] = numberedInvoiceScenario($this);

    $this->actingAs($admin)->post(route('sales-adjustments.void', $credit), ['void_reason' => 'Issued by mistake']);

    $this->actingAs($admin)
        ->get(route('orders.combined-invoice', ['customer' => $customer->id, 'trip_id' => $trip->id]))
        ->assertOk()
        ->assertDontSeeText($credit->adjustment_number)
        ->assertSeeText($return->adjustment_number);
});

test('only a refund linked to a Credit Note or Sales Return has a document number', function () {
    [$admin, $trip, $customer, $order, $return, $credit] = numberedInvoiceScenario($this);

    $deposit = Payment::where('order_id', $order->id)->where('type', 'deposit')->first();
    $linked  = Payment::where('sales_adjustment_id', $credit->id)->first();
    $loose   = Payment::factory()->create(['order_id' => $order->id, 'amount' => 1000, 'type' => 'refund', 'paid_at' => now(), 'voided_at' => null, 'verification_status' => 'verified']);

    expect($deposit->documentNumber())->toBeNull();
    expect($linked->documentNumber())->toBe($credit->adjustment_number);
    expect($loose->documentNumber())->toBeNull(); // a refund with no document behind it has nothing to show
});
