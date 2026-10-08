<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesAdjustment;
use App\Models\User;

/*
 * The Returns & Credit Notes list can be searched by customer (name or phone), by
 * order number, or by the document's own number (RP/… or CR/…). The search combines
 * with the Type and Trip filters, treats "%" and "_" as ordinary characters, and
 * never shows someone else's documents to staff who only see their own orders.
 *
 * Scenario: Alice gets a Credit Note, Budi gets a Sales Return — both on the same trip.
 */

/** A paid order for $name with one item (qty 3 x 100,000), then one document issued on it. */
function srIssue($test, $admin, $trip, string $name, string $phone, string $type, $createdBy = null): array
{
    $customer = $test->customer($admin);
    $customer->update(['name' => $name, 'phone' => $phone, 'default_shipping_area_id' => null]);

    $order = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => ($createdBy ?? $admin)->id,
        'shipping_area_id' => null, 'order_number' => null,
        'subtotal' => 300000, 'total_amount' => 300000, 'discount_amount' => 0, 'shipping_fee' => 0,
        'shipping_discount' => 0, 'deposit_paid' => 0, 'payment_status' => 'unpaid',
    ]);
    $product = Product::create([
        'trip_id' => $trip->id, 'product_code' => 'SR' . fake()->unique()->numerify('######'),
        'price' => 100000, 'weight_gram' => 100, 'status' => 'active',
    ]);
    $item = OrderItem::create([
        'order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 3,
        'unit_price' => 100000, 'line_total' => 300000, 'status' => 'pending',
    ]);

    $test->actingAs($admin)->post(route('orders.payments.add', $order), [
        'amount' => 300000, 'type' => 'deposit', 'paid_at' => now()->format('Y-m-d'),
    ])->assertSessionDoesntHaveErrors();
    $test->actingAs($admin)->post(route('payments.verify', Payment::where('order_id', $order->id)->latest('id')->first()));

    $payload = $type === 'return'
        ? ['type' => 'return', 'items' => [['order_item_id' => $item->id, 'quantity' => 1]]]
        : ['type' => 'credit_note', 'amount' => 10000];
    $test->actingAs($admin)->post(route('orders.sales-adjustments.store', $order), $payload)->assertSessionDoesntHaveErrors();

    return [$order->fresh(), SalesAdjustment::where('order_id', $order->id)->latest('id')->firstOrFail()];
}

/** Alice (credit note) and Budi (sales return) on one trip. */
function srTwoDocuments($test): array
{
    $admin = $test->adminUser();
    $trip  = $test->openTrip();
    $trip->update(['batch_number' => 7]);

    [$aliceOrder, $aliceDoc] = srIssue($test, $admin, $trip, 'Alice Tan', '081200000001', 'credit_note');
    [$budiOrder,  $budiDoc]  = srIssue($test, $admin, $trip, 'Budi Santoso', '081200000002', 'return');

    return [$admin, $trip, $aliceOrder, $aliceDoc, $budiOrder, $budiDoc];
}

function srList($test, $user, array $query = [])
{
    return $test->actingAs($user)->get(route('sales-adjustments.index', $query))->assertOk();
}

test('searching by customer name finds only that customer, whatever the case or part typed', function () {
    [$admin, $trip, $aOrder, $aDoc, $bOrder, $bDoc] = srTwoDocuments($this);

    srList($this, $admin, ['search' => 'alice'])
        ->assertSeeText($aDoc->adjustment_number)
        ->assertDontSeeText($bDoc->adjustment_number);

    srList($this, $admin, ['search' => 'SANTOS'])
        ->assertSeeText($bDoc->adjustment_number)
        ->assertDontSeeText($aDoc->adjustment_number);
});

test('searching by phone number works too', function () {
    [$admin, $trip, $aOrder, $aDoc, $bOrder, $bDoc] = srTwoDocuments($this);

    srList($this, $admin, ['search' => '0812000000'])->assertSeeText($aDoc->adjustment_number)->assertSeeText($bDoc->adjustment_number);
    srList($this, $admin, ['search' => '081200000002'])->assertSeeText($bDoc->adjustment_number)->assertDontSeeText($aDoc->adjustment_number);
});

test('searching by the order number finds the documents on that order', function () {
    [$admin, $trip, $aOrder, $aDoc, $bOrder, $bDoc] = srTwoDocuments($this);

    srList($this, $admin, ['search' => $bOrder->order_number])
        ->assertSeeText($bDoc->adjustment_number)
        ->assertDontSeeText($aDoc->adjustment_number);
});

test('searching by the Credit Note or Sales Return number — whole or in part', function () {
    [$admin, $trip, $aOrder, $aDoc, $bOrder, $bDoc] = srTwoDocuments($this);

    // the whole number
    srList($this, $admin, ['search' => $aDoc->adjustment_number])
        ->assertSeeText($aDoc->adjustment_number)->assertDontSeeText($bDoc->adjustment_number);

    // just the prefix: CR/ finds the credit note only, RP/ the return only
    srList($this, $admin, ['search' => 'CR/'])->assertSeeText($aDoc->adjustment_number)->assertDontSeeText($bDoc->adjustment_number);
    srList($this, $admin, ['search' => 'RP/'])->assertSeeText($bDoc->adjustment_number)->assertDontSeeText($aDoc->adjustment_number);
});

test('the search combines with the type and trip filters instead of replacing them', function () {
    [$admin, $trip, $aOrder, $aDoc, $bOrder, $bDoc] = srTwoDocuments($this);

    // "alice" matches Alice, but she only has a Credit Note — so filtering to Sales Returns leaves nothing.
    srList($this, $admin, ['search' => 'alice', 'type' => 'return'])
        ->assertDontSeeText($aDoc->adjustment_number)->assertSeeText('Nothing matches');

    srList($this, $admin, ['search' => 'alice', 'type' => 'credit_note'])->assertSeeText($aDoc->adjustment_number);

    // a different trip has none of these
    $other = $this->openTrip();
    srList($this, $admin, ['search' => 'alice', 'trip_id' => $other->id])->assertDontSeeText($aDoc->adjustment_number);
    srList($this, $admin, ['search' => 'alice', 'trip_id' => $trip->id])->assertSeeText($aDoc->adjustment_number);
});

test('with nothing typed every document is listed, and spaces around a search are ignored', function () {
    [$admin, $trip, $aOrder, $aDoc, $bOrder, $bDoc] = srTwoDocuments($this);

    srList($this, $admin)->assertSeeText($aDoc->adjustment_number)->assertSeeText($bDoc->adjustment_number);
    srList($this, $admin, ['search' => '   '])->assertSeeText($aDoc->adjustment_number)->assertSeeText($bDoc->adjustment_number);
    srList($this, $admin, ['search' => '  alice  '])->assertSeeText($aDoc->adjustment_number)->assertDontSeeText($bDoc->adjustment_number);
});

test('"%" and "_" are searched as ordinary characters, not as wildcards', function () {
    [$admin, $trip, $aOrder, $aDoc, $bOrder, $bDoc] = srTwoDocuments($this);

    // As wildcards both would match everything; as literals nothing here contains them.
    srList($this, $admin, ['search' => '%'])->assertDontSeeText($aDoc->adjustment_number)->assertDontSeeText($bDoc->adjustment_number);
    srList($this, $admin, ['search' => '_'])->assertDontSeeText($aDoc->adjustment_number)->assertDontSeeText($bDoc->adjustment_number);
    srList($this, $admin, ['search' => 'A%e'])->assertDontSeeText($aDoc->adjustment_number); // "Alice" would match "A%e" if % were a wildcard
});

test('a search with no match says so', function () {
    [$admin] = srTwoDocuments($this);

    srList($this, $admin, ['search' => 'zzz-nobody'])->assertSeeText('Nothing matches');
});

test('a voided document can still be found, shown struck through', function () {
    [$admin, $trip, $aOrder, $aDoc, $bOrder, $bDoc] = srTwoDocuments($this);

    $this->actingAs($admin)->post(route('sales-adjustments.void', $aDoc), ['void_reason' => 'test']);

    $html = srList($this, $admin, ['search' => $aDoc->adjustment_number])->assertSeeText($aDoc->adjustment_number)->getContent();
    expect($html)->toContain('text-decoration-line-through');
});

test('the box keeps what was typed, and Clear appears once a search is active', function () {
    [$admin] = srTwoDocuments($this);

    $html = srList($this, $admin, ['search' => 'alice'])->getContent();
    expect($html)->toContain('value="alice"');
    expect($html)->toContain('name="search"');
    expect($html)->toContain('>Clear<');

    expect(srList($this, $admin)->getContent())->not->toContain('>Clear<');
});

test('staff who only see their own orders cannot find anyone else\'s documents by searching', function () {
    $admin = $this->adminUser();
    $staff = User::factory()->ownDataOnly()->create(['permissions' => ['orders.sales_adjustments' => true]]);
    $trip  = $this->openTrip();
    $trip->update(['batch_number' => 7]);

    [$mineOrder,   $mineDoc]   = srIssue($this, $admin, $trip, 'Mine Customer', '081300000001', 'credit_note', $staff);
    [$theirsOrder, $theirsDoc] = srIssue($this, $admin, $trip, 'Theirs Customer', '081300000002', 'credit_note', $admin);

    // They search for the other person's customer by name, order number and document number: nothing.
    foreach (['Theirs', $theirsOrder->order_number, $theirsDoc->adjustment_number] as $term) {
        srList($this, $staff, ['search' => $term])->assertDontSeeText($theirsDoc->adjustment_number);
    }
    // ...and their own is still found.
    srList($this, $staff, ['search' => 'Mine'])->assertSeeText($mineDoc->adjustment_number);
});

test('the page is still closed to staff without the Returns & Credits permission, search or not', function () {
    $staff = $this->staffUser(['permissions' => ['orders.sales_adjustments' => false]]);

    $this->actingAs($staff)->get(route('sales-adjustments.index', ['search' => 'anything']))->assertForbidden();
});
