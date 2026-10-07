<?php

use App\Models\Order;
use App\Models\Payment;

/*
 * The Payment Log used to show a "Total Verified (Rp)" card. It was removed on request,
 * so no money total is shown or worked out there any more. What stays is the row of
 * three counts — Unverified, Verified, Disputed — for people who can verify payments.
 */

function plOrderWithPayments($test, $admin, $trip, array $payments): void
{
    $customer = $test->customer($admin);
    $order = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'order_number' => null]);
    foreach ($payments as $p) {
        Payment::create(array_merge([
            'order_id' => $order->id, 'type' => 'deposit', 'method' => 'Transfer',
            'paid_at' => now(), 'recorded_by' => $admin->id, 'verification_status' => 'verified',
        ], $p));
    }
}

test('the Payment Log no longer shows a Total Verified amount', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    plOrderWithPayments($this, $admin, $trip, [
        ['amount' => 100000],
        ['amount' => 30000, 'type' => 'refund', 'method' => 'Refund'],
    ]);

    $this->actingAs($admin)->get(route('payments.index', ['trip_id' => $trip->id, 'tab' => 'log']))
        ->assertOk()
        ->assertDontSeeText('Total Verified')
        ->assertDontSeeText('70.000');   // the net total (100.000 received - 30.000 refunded) must not appear anywhere
});

test('the three counts are still there, with the right numbers', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    plOrderWithPayments($this, $admin, $trip, [
        ['amount' => 1000, 'verification_status' => 'verified'],
        ['amount' => 2000, 'verification_status' => 'verified'],
        ['amount' => 3000, 'verification_status' => 'unverified'],
        ['amount' => 4000, 'verification_status' => 'disputed'],
    ]);

    $html = $this->actingAs($admin)->get(route('payments.index', ['trip_id' => $trip->id, 'tab' => 'log']))
        ->assertOk()->assertSeeText('Unverified')->assertSeeText('Disputed')->getContent();

    expect($html)->toContain('text-warning fs-5">1</div>');   // 1 unverified
    expect($html)->toContain('text-success fs-5">2</div>');   // 2 verified
    expect($html)->toContain('text-danger fs-5">1</div>');    // 1 disputed
});

test('staff who cannot verify payments do not see the counts bar at all', function () {
    $admin = $this->adminUser();
    $staff = $this->staffUser(['permissions' => ['payments.verify' => false]]);
    $trip  = $this->openTrip();
    plOrderWithPayments($this, $admin, $trip, [['amount' => 5000]]);

    $response = $this->actingAs($staff)->get(route('payments.index', ['trip_id' => $trip->id, 'tab' => 'log']));
    $response->assertOk();   // they can open the log — it just has no counts bar for them
    $html = $response->getContent();

    expect($html)->not->toContain('text-warning fs-5');
    expect($html)->not->toContain('Total Verified');
});
