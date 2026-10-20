<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shipment;
use App\Models\ShipmentBatch;
use App\Models\Trip;
use App\Models\User;

/*
 * The "Ready to Pack" tab is now the "Shipment" tab. Only customers who are fully paid (every
 * order paid, every payment verified) are listed. Packages are not always sent on one day, so:
 *
 *   1) the TOTAL number of packages is set once (shipments.package_total), then
 *   2) every sending is logged with its own number of packages and date (shipment_batches),
 *      e.g. 10 packages = 3 on 10 Oct + 3 on 11 Oct + the rest (4) on 12 Oct.
 */

function shCustomer($test, User $admin, Trip $trip, float $total = 500000, string $status = 'paid', string $verification = 'verified'): Customer
{
    $customer = $test->customer($admin);
    $order = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
        'subtotal' => $total, 'total_amount' => $total,
        'deposit_paid' => $status === 'paid' ? $total : 0, 'payment_status' => $status,
    ]);
    if ($status === 'paid') {
        Payment::factory()->create([
            'order_id' => $order->id, 'amount' => $total, 'type' => 'deposit',
            'paid_at' => now(), 'voided_at' => null, 'verification_status' => $verification,
        ]);
    }
    return $customer;
}

function shTab($test, User $admin, Trip $trip, array $extra = [])
{
    return $test->actingAs($admin)->get(route('payments.index', ['trip_id' => $trip->id, 'tab' => 'shipment'] + $extra));
}

function shTotal($test, User $admin, Customer $customer, Trip $trip, $total, array $extra = [])
{
    return $test->actingAs($admin)->post(route('payments.shipment.save'), [
        'customer_id' => $customer->id, 'trip_id' => $trip->id, 'package_total' => $total,
    ] + $extra);
}

function shAdd($test, User $admin, Customer $customer, Trip $trip, array $fields)
{
    return $test->actingAs($admin)->post(route('payments.shipment.batch.store'), [
        'customer_id' => $customer->id, 'trip_id' => $trip->id,
    ] + $fields);
}

function shShipment(Customer $customer, Trip $trip): ?Shipment
{
    return Shipment::where('customer_id', $customer->id)->where('trip_id', $trip->id)->first();
}

function shRow(string $html, Customer $customer): string
{
    $row = substr($html, strpos($html, 'id="ship-' . $customer->id . '"'));
    return substr($row, 0, strpos($row, '</tr>'));
}

test('the tab is called Shipment, not Ready to Pack', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();

    $this->actingAs($admin)->get(route('payments.index', ['trip_id' => $trip->id]))
        ->assertOk()->assertSee('Shipment')->assertDontSee('Ready to Pack');
});

test('only fully paid and verified customers are listed, with their total', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();

    $paid       = shCustomer($this, $admin, $trip, 750000);
    $unpaid     = shCustomer($this, $admin, $trip, 300000, 'unpaid');
    $unverified = shCustomer($this, $admin, $trip, 410000, 'paid', 'unverified');

    $html = shTab($this, $admin, $trip)->assertOk()->getContent();

    expect($html)->toContain(e($paid->name))->toContain('Rp 750.000')
        ->and($html)->not->toContain(e($unpaid->name))
        ->and($html)->not->toContain(e($unverified->name));
});

test('a customer with one order still unpaid stays off the list', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();

    $customer = shCustomer($this, $admin, $trip, 500000);
    Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
        'subtotal' => 100000, 'total_amount' => 100000, 'deposit_paid' => 0, 'payment_status' => 'unpaid',
    ]);

    shTab($this, $admin, $trip)->assertOk()->assertDontSee($customer->name);
});

test('the old tab=pack address still opens the Shipment tab', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $customer = shCustomer($this, $admin, $trip);

    $this->actingAs($admin)->get(route('payments.index', ['trip_id' => $trip->id, 'tab' => 'pack']))
        ->assertOk()->assertSee($customer->name)->assertSee('name="package_total"', false);
});

// ── Step 1: the total ───────────────────────────────────────────────

test('the total number of packages is saved first, with nothing sent yet', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $customer = shCustomer($this, $admin, $trip);

    shTotal($this, $admin, $customer, $trip, 10)->assertSessionHas('success');

    $shipment = shShipment($customer, $trip);
    expect($shipment->package_total)->toBe(10)->and($shipment->packagesSent())->toBe(0)
        ->and($shipment->updated_by)->toBe($admin->id);
    expect(shRow(shTab($this, $admin, $trip)->getContent(), $customer))->toContain('0 of 10 sent');
});

test('an empty total is refused, so a saved total cannot be wiped by accident', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $customer = shCustomer($this, $admin, $trip);

    shTotal($this, $admin, $customer, $trip, 6);
    shTotal($this, $admin, $customer, $trip, '')->assertSessionHas('error');

    expect(shShipment($customer, $trip)->package_total)->toBe(6);
});

test('a package number must be a whole number from 1 up', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $customer = shCustomer($this, $admin, $trip);

    foreach (['0', '-2', 'abc', '2.5', '100000'] as $bad) {
        shTotal($this, $admin, $customer, $trip, $bad)->assertSessionHasErrors('package_total');
    }
    expect(Shipment::count())->toBe(0);

    shTotal($this, $admin, $customer, $trip, 5);
    foreach (['0', 'abc', '2.5'] as $bad) {
        shAdd($this, $admin, $customer, $trip, ['packages' => $bad])->assertSessionHasErrors('packages');
    }
    expect(ShipmentBatch::count())->toBe(0);
});

test('the total can be raised or lowered, but never below what is already sent', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $customer = shCustomer($this, $admin, $trip);

    shTotal($this, $admin, $customer, $trip, 10);
    shAdd($this, $admin, $customer, $trip, ['packages' => 6]);

    shTotal($this, $admin, $customer, $trip, 5)->assertSessionHas('error');   // 6 already sent
    expect(shShipment($customer, $trip)->package_total)->toBe(10);

    shTotal($this, $admin, $customer, $trip, 6)->assertSessionHas('success'); // exactly what is sent: fine
    shTotal($this, $admin, $customer, $trip, 12)->assertSessionHas('success');
    expect(shShipment($customer, $trip)->package_total)->toBe(12);
});

// ── Step 2: every sending ───────────────────────────────────────────

test('a sending is logged with its packages, and today is used when no date is typed', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $customer = shCustomer($this, $admin, $trip);

    shTotal($this, $admin, $customer, $trip, 10);
    shAdd($this, $admin, $customer, $trip, ['packages' => 3])->assertSessionHas('success');

    $batch = ShipmentBatch::first();
    expect($batch->packages)->toBe(3)
        ->and($batch->shipped_on->toDateString())->toBe(now()->toDateString())
        ->and($batch->created_by)->toBe($admin->id);

    $row = shRow(shTab($this, $admin, $trip)->getContent(), $customer);
    expect($row)->toContain('3 of 10 sent')->toContain('7 left');
});

test('10 packages can go out in three sendings on three different days', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $customer = shCustomer($this, $admin, $trip);

    shTotal($this, $admin, $customer, $trip, 10);
    shAdd($this, $admin, $customer, $trip, ['packages' => 3, 'shipped_on' => '2026-10-10']);
    shAdd($this, $admin, $customer, $trip, ['packages' => 3, 'shipped_on' => '2026-10-11']);

    // two sent, the row says how many are left
    $row = shRow(shTab($this, $admin, $trip)->getContent(), $customer);
    expect($row)->toContain('6 of 10 sent')->toContain('4 left')
        ->and($row)->toContain('10 Oct 2026')->toContain('11 Oct 2026');

    // "Send the rest" logs exactly what is left, on the date given
    shAdd($this, $admin, $customer, $trip, ['rest' => 1, 'shipped_on' => '2026-10-12'])->assertSessionHas('success');

    $batches = shShipment($customer, $trip)->batches()->get();
    expect($batches->pluck('packages')->all())->toBe([3, 3, 4])
        ->and($batches->map(fn($b) => $b->shipped_on->toDateString())->all())->toBe(['2026-10-10', '2026-10-11', '2026-10-12']);

    $row = shRow(shTab($this, $admin, $trip)->getContent(), $customer);
    expect($row)->toContain('10 of 10 sent')->not->toContain('left')
        ->and($row)->toContain('12 Oct 2026');
});

test('more packages than are left cannot be logged', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $customer = shCustomer($this, $admin, $trip);

    shTotal($this, $admin, $customer, $trip, 10);
    shAdd($this, $admin, $customer, $trip, ['packages' => 8]);
    shAdd($this, $admin, $customer, $trip, ['packages' => 3])->assertSessionHas('error'); // only 2 left

    expect(ShipmentBatch::count())->toBe(1);
});

test('nothing can be sent before the total is set, or once everything is already sent', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $customer = shCustomer($this, $admin, $trip);

    shAdd($this, $admin, $customer, $trip, ['packages' => 1])->assertSessionHas('error');
    expect(ShipmentBatch::count())->toBe(0);

    shTotal($this, $admin, $customer, $trip, 2);
    shAdd($this, $admin, $customer, $trip, ['packages' => 2]);
    shAdd($this, $admin, $customer, $trip, ['rest' => 1])->assertSessionHas('error');
    shAdd($this, $admin, $customer, $trip, ['packages' => 1])->assertSessionHas('error');
    expect(ShipmentBatch::count())->toBe(1);
});

test('a sending needs a number of packages unless it is "send the rest"', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $customer = shCustomer($this, $admin, $trip);

    shTotal($this, $admin, $customer, $trip, 5);
    shAdd($this, $admin, $customer, $trip, ['packages' => ''])->assertSessionHas('error');
    expect(ShipmentBatch::count())->toBe(0);
});

test('a customer who is not fully paid cannot be shipped, and nothing is saved', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $unpaid     = shCustomer($this, $admin, $trip, 300000, 'unpaid');
    $unverified = shCustomer($this, $admin, $trip, 410000, 'paid', 'unverified');

    foreach ([$unpaid, $unverified] as $customer) {
        shTotal($this, $admin, $customer, $trip, 4)->assertSessionHas('error');
        shAdd($this, $admin, $customer, $trip, ['packages' => 2])->assertSessionHas('error');
    }
    expect(Shipment::count())->toBe(0)->and(ShipmentBatch::count())->toBe(0);
});

test('a logged sending can be corrected, but not beyond the total', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $customer = shCustomer($this, $admin, $trip);

    shTotal($this, $admin, $customer, $trip, 10);
    shAdd($this, $admin, $customer, $trip, ['packages' => 3, 'shipped_on' => '2026-10-10']);
    shAdd($this, $admin, $customer, $trip, ['packages' => 3, 'shipped_on' => '2026-10-11']);
    $first = ShipmentBatch::orderBy('id')->first();

    $this->actingAs($admin)->put(route('payments.shipment.batch.update', $first), ['packages' => 4, 'shipped_on' => '2026-10-09'])
        ->assertSessionHas('success');
    expect($first->fresh()->packages)->toBe(4)->and($first->fresh()->shipped_on->toDateString())->toBe('2026-10-09');

    // 4 + 3 = 7 sent; raising the first to 8 would make 11 > 10
    $this->actingAs($admin)->put(route('payments.shipment.batch.update', $first), ['packages' => 8, 'shipped_on' => '2026-10-09'])
        ->assertSessionHas('error');
    expect($first->fresh()->packages)->toBe(4);
});

test('a logged sending can be deleted, and its packages are left to send again', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $customer = shCustomer($this, $admin, $trip);

    shTotal($this, $admin, $customer, $trip, 10);
    shAdd($this, $admin, $customer, $trip, ['rest' => 1]); // all 10 sent
    expect(shRow(shTab($this, $admin, $trip)->getContent(), $customer))->toContain('10 of 10 sent');

    $this->actingAs($admin)->delete(route('payments.shipment.batch.destroy', ShipmentBatch::first()))->assertSessionHas('success');

    expect(ShipmentBatch::count())->toBe(0)->and(shShipment($customer, $trip)->package_total)->toBe(10);
    expect(shRow(shTab($this, $admin, $trip)->getContent(), $customer))->toContain('0 of 10 sent');
});

test('removing the whole shipment deletes the total and every sending with it', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $customer = shCustomer($this, $admin, $trip);

    shTotal($this, $admin, $customer, $trip, 10);
    shAdd($this, $admin, $customer, $trip, ['packages' => 3]);
    shTotal($this, $admin, $customer, $trip, '', ['clear' => 1])->assertSessionHas('success');

    expect(Shipment::count())->toBe(0)->and(ShipmentBatch::count())->toBe(0);
});

test('the saved message names the customer, and that row is highlighted on return', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $customer = shCustomer($this, $admin, $trip);

    shTotal($this, $admin, $customer, $trip, 10);
    $response = shAdd($this, $admin, $customer, $trip, ['packages' => 3, 'shipped_on' => '2026-10-10']);

    $response->assertSessionHas('success', fn($m) => str_contains($m, $customer->name) && str_contains($m, '3 package') && str_contains($m, '7 left'));
    $response->assertSessionHas('shipment_saved', $customer->id);
    expect($response->headers->get('Location'))->toEndWith('#ship-' . $customer->id);
});

test('the sendings of one trip do not show on another trip', function () {
    $admin = $this->adminUser();
    $trip1 = $this->openTrip();
    $trip2 = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $customer = shCustomer($this, $admin, $trip1);
    $order = Order::factory()->create([
        'trip_id' => $trip2->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
        'subtotal' => 200000, 'total_amount' => 200000, 'deposit_paid' => 200000, 'payment_status' => 'paid',
    ]);
    Payment::factory()->create(['order_id' => $order->id, 'amount' => 200000, 'type' => 'deposit',
        'paid_at' => now(), 'voided_at' => null, 'verification_status' => 'verified']);

    shTotal($this, $admin, $customer, $trip1, 7);
    shAdd($this, $admin, $customer, $trip1, ['packages' => 2, 'shipped_on' => '2026-10-15']);

    $other = shRow(shTab($this, $admin, $trip2)->assertOk()->getContent(), $customer);
    expect($other)->not->toContain('2 of 7')->not->toContain('15 Oct 2026')->toContain('name="package_total"');
    expect(shRow(shTab($this, $admin, $trip1)->getContent(), $customer))->toContain('2 of 7 sent');
});

// ── What the page shows ─────────────────────────────────────────────

test('a saved total is locked until Edit total is pressed, a new row is open for typing', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $set = shCustomer($this, $admin, $trip);
    $new = shCustomer($this, $admin, $trip);
    shTotal($this, $admin, $set, $trip, 4);

    $html = shTab($this, $admin, $trip)->assertOk()->getContent();

    $setRow = shRow($html, $set);
    expect($setRow)->toContain('shipOpen(')
        ->and($setRow)->toMatch('/name="package_total"[^>]*disabled/');

    $newRow = shRow($html, $new);
    expect($newRow)->not->toContain('shipOpen(')
        ->and($newRow)->toContain('name="package_total"')
        ->and($newRow)->not->toMatch('/name="package_total"[^>]*disabled/');
});

test('a saved sending is locked until Edit is pressed', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $customer = shCustomer($this, $admin, $trip);
    shTotal($this, $admin, $customer, $trip, 10);
    shAdd($this, $admin, $customer, $trip, ['packages' => 3, 'shipped_on' => '2026-10-10']);

    $row = shRow(shTab($this, $admin, $trip)->getContent(), $customer);
    expect($row)->toContain('3 packages')->toContain('10 Oct 2026')
        ->and($row)->toMatch('/name="packages"[^>]*value="3"[^>]*disabled/');
});

test('the add-a-sending form disappears once every package is sent', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $customer = shCustomer($this, $admin, $trip);
    shTotal($this, $admin, $customer, $trip, 4);

    expect(shRow(shTab($this, $admin, $trip)->getContent(), $customer))->toContain('Send the rest (4)');

    shAdd($this, $admin, $customer, $trip, ['rest' => 1]);
    expect(shRow(shTab($this, $admin, $trip)->getContent(), $customer))->not->toContain('Send the rest');
});

test('package boxes are text boxes for digits, so the mouse wheel cannot change them', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $customer = shCustomer($this, $admin, $trip);
    shCustomer($this, $admin, $trip);
    shTotal($this, $admin, $customer, $trip, 4);

    $html = shTab($this, $admin, $trip)->getContent();
    expect($html)->toMatch('/<input type="text" name="package_total" inputmode="numeric"/')
        ->and($html)->toMatch('/<input type="text" name="packages" inputmode="numeric"/')
        ->and($html)->not->toMatch('/<input type="number" name="(package_total|packages)"/');
});

test('the buttons filter into To ship, Partly sent and All sent, and show the counts', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $none    = shCustomer($this, $admin, $trip);   // nothing sent (not even a total)
    $total   = shCustomer($this, $admin, $trip);   // total set, nothing sent
    $partial = shCustomer($this, $admin, $trip);
    $done    = shCustomer($this, $admin, $trip);
    shTotal($this, $admin, $total, $trip, 5);
    shTotal($this, $admin, $partial, $trip, 5);
    shAdd($this, $admin, $partial, $trip, ['packages' => 2]);
    shTotal($this, $admin, $done, $trip, 5);
    shAdd($this, $admin, $done, $trip, ['rest' => 1]);

    $all     = shTab($this, $admin, $trip)->getContent();
    $pending = shTab($this, $admin, $trip, ['ship' => 'pending'])->getContent();
    $partlyH = shTab($this, $admin, $trip, ['ship' => 'partial'])->getContent();
    $sentH   = shTab($this, $admin, $trip, ['ship' => 'shipped'])->getContent();

    foreach ([$none, $total, $partial, $done] as $c) expect($all)->toContain(e($c->name));

    expect($pending)->toContain(e($none->name))->toContain(e($total->name))
        ->not->toContain(e($partial->name))->not->toContain(e($done->name));
    expect($partlyH)->toContain(e($partial->name))->not->toContain(e($none->name))->not->toContain(e($done->name));
    expect($sentH)->toContain(e($done->name))->not->toContain(e($partial->name))->not->toContain(e($none->name));

    // counts: All 4, To ship 2, Partly sent 1, All sent 1
    expect($all)->toMatch('/All\s*<span[^>]*>4<\/span>/')
        ->and($all)->toMatch('/To ship\s*<span[^>]*>2<\/span>/')
        ->and($all)->toMatch('/Partly sent\s*<span[^>]*>1<\/span>/')
        ->and($all)->toMatch('/All sent\s*<span[^>]*>1<\/span>/');
});

test('the filter buttons keep the search and the trip', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $customer = shCustomer($this, $admin, $trip);

    $html = shTab($this, $admin, $trip, ['search' => $customer->name])->getContent();
    expect($html)->toContain('ship=pending')->toContain('ship=partial')->toContain('trip_id=' . $trip->id);
});

test('the tab badge counts customers that still have packages to send', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $untouched = shCustomer($this, $admin, $trip);
    $partial   = shCustomer($this, $admin, $trip);
    $done      = shCustomer($this, $admin, $trip);
    shCustomer($this, $admin, $trip, 100000, 'unpaid');

    shTotal($this, $admin, $partial, $trip, 5);
    shAdd($this, $admin, $partial, $trip, ['packages' => 2]);
    shTotal($this, $admin, $done, $trip, 5);
    shAdd($this, $admin, $done, $trip, ['rest' => 1]);

    $html = shTab($this, $admin, $trip)->assertOk()->getContent();
    expect($html)->toMatch('/Shipment\s*<span class="badge bg-success ms-1"[^>]*>2<\/span>/'); // untouched + partial
});

test('printing still works from the Shipment tab', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $customer = shCustomer($this, $admin, $trip);

    $this->actingAs($admin)->post(route('payments.mark-printed'), ['customer_id' => $customer->id, 'trip_id' => $trip->id])->assertRedirect();

    shTab($this, $admin, $trip)->assertOk()->assertSee('Printed');
    expect(Order::where('customer_id', $customer->id)->first()->invoice_printed_at)->not->toBeNull();
});
