<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shipment;
use App\Models\ShipmentBatch;
use App\Models\Trip;
use App\Models\User;

/*
 * Staff usually do not know the total number of packages on the first day. So the Shipment tab
 * has NO total box for a new customer: type "Sent now" (+ a date) and press Send.
 *
 *   23 Nov: 1 package sent  -> total becomes 1
 *   25 Nov: 1 more package  -> total becomes 2     (no need to touch the total)
 *
 * The total only matters if staff set it themselves ("Edit total") to keep track of what is
 * still to come; sending more than is left then raises it (add=1, sent by the page after a
 * confirmation).
 */

/** @param mixed $test */
function osCustomer($test, User $admin, Trip $trip): Customer
{
    $customer = $test->customer($admin);
    $order = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
        'subtotal' => 500000, 'total_amount' => 500000, 'deposit_paid' => 500000, 'payment_status' => 'paid',
    ]);
    Payment::factory()->create([
        'order_id' => $order->id, 'amount' => 500000, 'type' => 'deposit',
        'paid_at' => now(), 'voided_at' => null, 'verification_status' => 'verified',
    ]);

    return $customer;
}

/** @param mixed $test */
function osSend($test, User $admin, Customer $customer, Trip $trip, array $fields)
{
    return $test->actingAs($admin)->post(route('payments.shipment.batch.store'), [
        'customer_id' => $customer->id, 'trip_id' => $trip->id,
    ] + $fields);
}

function osShipment(Customer $customer, Trip $trip): ?Shipment
{
    return Shipment::where('customer_id', $customer->id)->where('trip_id', $trip->id)->first();
}

/** @param mixed $test */
function osRow($test, User $admin, Trip $trip, Customer $customer): string
{
    $html  = $test->actingAs($admin)->get(route('payments.index', ['trip_id' => $trip->id, 'tab' => 'shipment']))->assertOk()->getContent();
    $start = strpos($html, 'id="ship-' . $customer->id . '"');

    return substr($html, $start, strpos($html, '</tr>', $start) - $start);
}

test('the first sending needs no total: the total becomes what was sent, dated today by default', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $customer = osCustomer($this, $admin, $trip);

    osSend($this, $admin, $customer, $trip, ['packages' => 1])->assertSessionHas('success');

    $shipment = osShipment($customer, $trip);
    expect($shipment->package_total)->toBe(1)
        ->and($shipment->packagesSent())->toBe(1)
        ->and($shipment->batches)->toHaveCount(1)
        ->and($shipment->batches->first()->shipped_on->toDateString())->toBe(now()->toDateString());
});

test('packages sent on different days simply add up, without ever typing a total', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $customer = osCustomer($this, $admin, $trip);

    // 23 Nov: 1 package (nobody knows yet how many there will be). 25 Nov: 1 more, the page adds=1 itself.
    osSend($this, $admin, $customer, $trip, ['packages' => 1, 'shipped_on' => '2026-11-23']);
    osSend($this, $admin, $customer, $trip, ['packages' => 1, 'shipped_on' => '2026-11-25', 'add' => 1])
        ->assertSessionHas('success');
    osSend($this, $admin, $customer, $trip, ['packages' => 3, 'shipped_on' => '2026-11-26', 'add' => 1]);

    $shipment = osShipment($customer, $trip);
    expect($shipment->package_total)->toBe(5)
        ->and($shipment->packagesSent())->toBe(5)
        ->and($shipment->batches->pluck('packages')->all())->toBe([1, 1, 3])
        ->and($shipment->batches->map(fn ($b) => $b->shipped_on->toDateString())->all())
            ->toBe(['2026-11-23', '2026-11-25', '2026-11-26']);
});

test('without add=1 a sending still cannot go past a saved total', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $customer = osCustomer($this, $admin, $trip);

    osSend($this, $admin, $customer, $trip, ['packages' => 2]);               // total 2, sent 2
    osSend($this, $admin, $customer, $trip, ['packages' => 1])->assertSessionHas('error');

    expect(osShipment($customer, $trip)->packagesSent())->toBe(2)
        ->and(ShipmentBatch::count())->toBe(1);
});

test('a total the staff set themselves is raised only as much as needed', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $customer = osCustomer($this, $admin, $trip);

    $this->actingAs($admin)->post(route('payments.shipment.save'), [
        'customer_id' => $customer->id, 'trip_id' => $trip->id, 'package_total' => 5,
    ]);
    osSend($this, $admin, $customer, $trip, ['packages' => 3]);                       // 3 of 5, 2 left
    osSend($this, $admin, $customer, $trip, ['packages' => 1, 'add' => 1]);           // fits: total stays 5
    expect(osShipment($customer, $trip)->package_total)->toBe(5);

    osSend($this, $admin, $customer, $trip, ['packages' => 4, 'add' => 1]);           // 4 sent + 4 > 5 -> total 8
    $shipment = osShipment($customer, $trip);
    expect($shipment->package_total)->toBe(8)->and($shipment->packagesSent())->toBe(8);
});

test('nothing typed is refused and nothing is saved', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $customer = osCustomer($this, $admin, $trip);

    osSend($this, $admin, $customer, $trip, [])->assertSessionHas('error');
    osSend($this, $admin, $customer, $trip, ['add' => 1])->assertSessionHas('error');

    expect(osShipment($customer, $trip))->toBeNull()->and(ShipmentBatch::count())->toBe(0);
});

test('a number below 1 or a rest on a customer with nothing sent is refused', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $customer = osCustomer($this, $admin, $trip);

    osSend($this, $admin, $customer, $trip, ['packages' => 0])->assertSessionHasErrors('packages');
    osSend($this, $admin, $customer, $trip, ['rest' => 1])->assertSessionHas('error');

    expect(osShipment($customer, $trip))->toBeNull();
});

test('an explicit total with a partial sending still works (older forms and the API)', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $customer = osCustomer($this, $admin, $trip);

    osSend($this, $admin, $customer, $trip, ['package_total' => 10, 'packages' => 4, 'shipped_on' => '2026-11-14']);
    osSend($this, $admin, $customer, $trip, ['rest' => 1, 'shipped_on' => '2026-11-18']);

    $shipment = osShipment($customer, $trip);
    expect($shipment->package_total)->toBe(10)
        ->and($shipment->batches->pluck('packages')->all())->toBe([4, 6]);

    // total given, sent now blank = all of them
    $other = osCustomer($this, $admin, $trip);
    osSend($this, $admin, $other, $trip, ['package_total' => 3]);
    expect(osShipment($other, $trip)->packagesSent())->toBe(3);

    // sending more than the given total on the first sending is refused
    $third = osCustomer($this, $admin, $trip);
    osSend($this, $admin, $third, $trip, ['package_total' => 2, 'packages' => 5])->assertSessionHas('error');
    expect(osShipment($third, $trip))->toBeNull();
});

test('a total typed on a stale page never overwrites a saved total', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $customer = osCustomer($this, $admin, $trip);

    osSend($this, $admin, $customer, $trip, ['package_total' => 5, 'packages' => 1]);
    osSend($this, $admin, $customer, $trip, ['package_total' => 9, 'packages' => 2]);

    $shipment = osShipment($customer, $trip);
    expect($shipment->package_total)->toBe(5)->and($shipment->packagesSent())->toBe(3);
});

test('a customer who is not fully paid still cannot be shipped', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $customer = $this->customer($admin);
    Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
        'subtotal' => 100000, 'total_amount' => 100000, 'deposit_paid' => 0, 'payment_status' => 'unpaid',
    ]);

    osSend($this, $admin, $customer, $trip, ['packages' => 1])->assertSessionHas('error');

    expect(osShipment($customer, $trip))->toBeNull();
});

test('a new row has only "Sent now", a date and Send — no total box', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $customer = osCustomer($this, $admin, $trip);

    $row = osRow($this, $admin, $trip, $customer);

    expect($row)->toContain('name="packages"')
        ->and($row)->toContain('name="shipped_on"')
        ->and($row)->toContain('Send')
        ->and($row)->not->toContain('package_total')
        ->and($row)->not->toContain('Total only')
        ->and($row)->not->toContain('Set total');
});

test('after a sending the row keeps a Send form, and Send the rest only while packages are left', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $customer = osCustomer($this, $admin, $trip);
    osSend($this, $admin, $customer, $trip, ['packages' => 1]);

    $row = osRow($this, $admin, $trip, $customer);
    expect($row)->toContain('name="packages"')->toContain('Send')
        ->and($row)->not->toContain('Send the rest')
        ->and($row)->not->toContain('Send as extra');
});
