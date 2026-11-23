<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Trip;
use App\Models\User;

/*
 * Reported: ALEXANDRA 1658 had two fully paid, verified orders plus one order
 * whose items were all sold out (total Rp 0, stored payment_status 'unpaid').
 * That Rp 0 order counted as "unpaid", so she never appeared in the Shipment
 * tab. An order that owes nothing must not block shipment.
 */

/** @param mixed $test */
function zeroCustomer($test, User $admin, Trip $trip, float $zeroDeposit = 0): Customer
{
    $customer = $test->customer($admin);

    $paid = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
        'subtotal' => 178000, 'total_amount' => 178000, 'deposit_paid' => 178000, 'payment_status' => 'paid',
    ]);
    Payment::factory()->create([
        'order_id' => $paid->id, 'amount' => 178000, 'type' => 'deposit',
        'paid_at' => now(), 'voided_at' => null, 'verification_status' => 'verified',
    ]);

    // The sold-out order: total Rp 0, nothing (or, in the edge case, some money) paid.
    Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
        'subtotal' => 0, 'total_amount' => 0, 'deposit_paid' => $zeroDeposit, 'payment_status' => 'unpaid',
    ]);

    return $customer;
}

test('a Rp 0 sold-out order does not keep a fully paid customer out of the Shipment tab', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = zeroCustomer($this, $admin, $trip);

    $this->actingAs($admin)
        ->get(route('payments.index', ['trip_id' => $trip->id, 'tab' => 'shipment']))
        ->assertOk()
        ->assertSee('id="ship-' . $customer->id . '"', false);

    // ...and a total can be saved for her.
    $this->actingAs($admin)->post(route('payments.shipment.save'), [
        'customer_id' => $customer->id, 'trip_id' => $trip->id, 'package_total' => 2,
    ])->assertSessionHas('success');
});

test('a Rp 0 order that still has money paid on it keeps blocking shipment (refund first)', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = zeroCustomer($this, $admin, $trip, zeroDeposit: 50000);

    $this->actingAs($admin)
        ->get(route('payments.index', ['trip_id' => $trip->id, 'tab' => 'shipment']))
        ->assertOk()
        ->assertDontSee('id="ship-' . $customer->id . '"', false);
});

test('a genuinely unpaid order still blocks shipment', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);

    Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
        'subtotal' => 100000, 'total_amount' => 100000, 'deposit_paid' => 0, 'payment_status' => 'unpaid',
    ]);

    $this->actingAs($admin)
        ->get(route('payments.index', ['trip_id' => $trip->id, 'tab' => 'shipment']))
        ->assertOk()
        ->assertDontSee('id="ship-' . $customer->id . '"', false);
});


test('a customer whose every item is sold out has nothing to ship, even though money was paid', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);

    // Like ANGELICA CHANDRA: Rp 500.000 paid, every item sold out -> total Rp 0, still saved as 'paid'.
    $order = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
        'subtotal' => 0, 'total_amount' => 0, 'deposit_paid' => 500000, 'payment_status' => 'paid',
    ]);
    Payment::factory()->create([
        'order_id' => $order->id, 'amount' => 500000, 'type' => 'deposit',
        'paid_at' => now(), 'voided_at' => null, 'verification_status' => 'verified',
    ]);

    $this->actingAs($admin)
        ->get(route('payments.index', ['trip_id' => $trip->id, 'tab' => 'shipment']))
        ->assertOk()
        ->assertDontSee('id="ship-' . $customer->id . '"', false);

    // ...and nothing can be logged as sent for her either.
    $this->actingAs($admin)->post(route('payments.shipment.batch.store'), [
        'customer_id' => $customer->id, 'trip_id' => $trip->id, 'packages' => 1,
    ])->assertSessionHas('error');
    expect(\App\Models\Shipment::count())->toBe(0);
});
