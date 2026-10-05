<?php

use App\Models\Order;
use App\Models\Payment;

/*
 * Design: Credit Note stays anchored to the order page, but the Overpaid row
 * on Payment Log jumps straight to a sensible default order instead of making
 * staff guess from a bare list of order numbers.
 *
 * A Credit Note refunds money, so it can only come from VERIFIED payments, and
 * from the order that holds the customer's spare verified credit (verified
 * above its own total; oldest wins a tie). Credit that is still unverified is
 * flagged instead of offered for refund. An override stays available and shows
 * each order's paid / total.
 */

function overpaidOrder(object $test, $trip, $customer, int $total, int $paid, bool $verified = true, array $extra = []): Order
{
    $order = Order::factory()->create(array_merge([
        'trip_id' => $trip->id, 'customer_id' => $customer->id,
        'total_amount' => $total, 'order_number' => null,
    ], $extra));

    if ($paid > 0) {
        Payment::factory()->create([
            'order_id' => $order->id, 'amount' => $paid, 'type' => 'deposit', 'paid_at' => now(),
            'voided_at' => null, 'verification_status' => $verified ? 'verified' : 'unverified',
        ]);
        $order->recalcPaymentStatus();
    }
    return $order->fresh();
}

test('a customer with exactly one order gets a direct Credit Note link to it, pre-filled', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $order = overpaidOrder($this, $trip, $customer, 100000, 170000); // overpaid by 70,000, verified

    $response = $this->actingAs($admin)->get(route('payments.index', ['trip_id' => $trip->id]));

    $response->assertOk();
    $response->assertSee(route('orders.show', $order->id) . '?open_credit_note=1&credit_amount=70000', false);
    $response->assertDontSee('Use a different order'); // only one order — no override needed
});

test('with multiple orders, the default is the order holding the payment — not just the oldest one', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);

    overpaidOrder($this, $trip, $customer, 50000, 0, true, ['created_at' => now()->subHour()]);   // older, nothing paid
    $orderB = overpaidOrder($this, $trip, $customer, 50000, 120000);                              // the money sits here

    $response = $this->actingAs($admin)->get(route('payments.index', ['trip_id' => $trip->id]));

    $response->assertOk();
    $response->assertSee(route('orders.show', $orderB->id) . '?open_credit_note=1&credit_amount=20000', false);
});

test('the override list shows each order\'s own paid and total, not just a bare order number', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $orderA = overpaidOrder($this, $trip, $customer, 50000, 50000);
    $orderB = overpaidOrder($this, $trip, $customer, 50000, 100000);

    $response = $this->actingAs($admin)->get(route('payments.index', ['trip_id' => $trip->id]));

    $response->assertOk();
    $response->assertSee('Use a different order');
    $response->assertSee($orderA->order_number);
    $response->assertSee($orderB->order_number);
    $response->assertSee('paid of');
    $response->assertSee('overpaid');
});

test('the default is the order with the largest SURPLUS, not simply the one that has paid the most', function () {
    // After credit is moved between orders, one order can hold a lot of money
    // that is all needed for its own total. Refunding from there would just
    // trigger more shuffling — the refund should come from where the spare is.
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);

    overpaidOrder($this, $trip, $customer, 15325000, 15325000, true, ['created_at' => now()->subHour()]); // paid the most, owes all of it
    $hasSpare = overpaidOrder($this, $trip, $customer, 3000000, 4675000);                                  // 1,675,000 genuinely spare

    $response = $this->actingAs($admin)->get(route('payments.index', ['trip_id' => $trip->id]));

    $response->assertOk();
    $response->assertSee(route('orders.show', $hasSpare->id) . '?open_credit_note=1&credit_amount=1675000', false);
});

test('credit that is only UNVERIFIED is not offered for refund — staff are told to verify first', function () {
    // The reported hole: a customer says they transferred, staff record it, and
    // the system would happily offer a refund of money that never arrived.
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $order = overpaidOrder($this, $trip, $customer, 100000, 170000, verified: false);

    $response = $this->actingAs($admin)->get(route('payments.index', ['trip_id' => $trip->id]));

    $response->assertOk();
    $response->assertSee('Verify payment first');
    $response->assertSee('unverified');
    $response->assertDontSee(route('orders.show', $order->id) . '?open_credit_note=1', false);
});

test('when only part of the credit is verified, only that part is offered', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $order = overpaidOrder($this, $trip, $customer, 100000, 130000);                 // 130,000 verified
    Payment::factory()->create([                                                      // plus 40,000 nobody has confirmed
        'order_id' => $order->id, 'amount' => 40000, 'type' => 'partial', 'paid_at' => now(),
        'voided_at' => null, 'verification_status' => 'unverified',
    ]);
    $order->recalcPaymentStatus(); // recorded: 170,000 vs 100,000 owed = 70,000 over, but only 30,000 verified

    $response = $this->actingAs($admin)->get(route('payments.index', ['trip_id' => $trip->id]));

    $response->assertOk();
    $response->assertSee(route('orders.show', $order->id) . '?open_credit_note=1&credit_amount=30000', false);
    $response->assertSee('40.000 unverified');
});

test('a customer with no overpayment does not appear on the Overpaid panel at all', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    overpaidOrder($this, $trip, $customer, 100000, 100000);

    $response = $this->actingAs($admin)->get(route('payments.index', ['trip_id' => $trip->id]));
    $response->assertDontSee('Overpaid — these customers');
});

test('visiting an order page via the shortcut auto-opens the Credit Note panel with the amount pre-filled', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $order = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'order_number' => null]);

    $response = $this->actingAs($admin)->get(route('orders.show', $order->id) . '?open_credit_note=1&credit_amount=70000');

    $response->assertOk();
    $response->assertSee('creditNotePanel');
    $response->assertSee('amountField.value = 70000', false);
});

test('visiting an order page normally does not auto-open the Credit Note panel', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $order = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'order_number' => null]);

    $response = $this->actingAs($admin)->get(route('orders.show', $order->id));

    $response->assertOk();
    $response->assertDontSee('amountField.value', false);
});
