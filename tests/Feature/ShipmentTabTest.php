<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shipment;
use App\Models\Trip;
use App\Models\User;

/*
 * The "Ready to Pack" tab is now the "Shipment" tab. Only customers who are fully paid (every
 * order paid, every payment verified) are listed, with the total amount (automatic), the number
 * of packages sent (typed in) and the shipping date (filled in when the package count is saved,
 * and editable).
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

function shSave($test, User $admin, Customer $customer, Trip $trip, array $fields)
{
    return $test->actingAs($admin)->post(route('payments.shipment.save'), [
        'customer_id' => $customer->id, 'trip_id' => $trip->id,
    ] + $fields);
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
        ->assertOk()->assertSee($customer->name)->assertSee('name="package_count"', false);
});

test('entering the package count fills in today as the shipping date', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $customer = shCustomer($this, $admin, $trip);

    shSave($this, $admin, $customer, $trip, ['package_count' => 3])->assertRedirect();

    $shipment = Shipment::where('customer_id', $customer->id)->where('trip_id', $trip->id)->first();
    expect($shipment->package_count)->toBe(3)
        ->and($shipment->shipped_on->toDateString())->toBe(now()->toDateString())
        ->and($shipment->updated_by)->toBe($admin->id);
});

test('a date typed in is kept instead of today', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $customer = shCustomer($this, $admin, $trip);

    shSave($this, $admin, $customer, $trip, ['package_count' => 2, 'shipped_on' => '2026-10-15']);

    expect(Shipment::first()->shipped_on->toDateString())->toBe('2026-10-15');
});

test('changing the package count later keeps the saved date', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $customer = shCustomer($this, $admin, $trip);

    shSave($this, $admin, $customer, $trip, ['package_count' => 2, 'shipped_on' => '2026-10-15']);
    shSave($this, $admin, $customer, $trip, ['package_count' => 4]); // date left blank

    $shipment = Shipment::first();
    expect($shipment->package_count)->toBe(4)
        ->and($shipment->shipped_on->toDateString())->toBe('2026-10-15')
        ->and(Shipment::count())->toBe(1);
});

test('the shipping date can be edited on its own', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $customer = shCustomer($this, $admin, $trip);

    shSave($this, $admin, $customer, $trip, ['package_count' => 2, 'shipped_on' => '2026-10-15']);
    shSave($this, $admin, $customer, $trip, ['package_count' => 2, 'shipped_on' => '2026-10-17']);

    expect(Shipment::first()->shipped_on->toDateString())->toBe('2026-10-17');
});

test('an empty package count is refused, so a saved count cannot be wiped by accident', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $customer = shCustomer($this, $admin, $trip);

    shSave($this, $admin, $customer, $trip, ['package_count' => 6, 'shipped_on' => '2026-10-15']);
    shSave($this, $admin, $customer, $trip, ['package_count' => '', 'shipped_on' => '2026-10-15'])
        ->assertSessionHas('error');

    $shipment = Shipment::first();
    expect($shipment->package_count)->toBe(6)->and($shipment->shipped_on->toDateString())->toBe('2026-10-15');
});

test('removing a shipment is its own action and clears the count and the date', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $customer = shCustomer($this, $admin, $trip);

    shSave($this, $admin, $customer, $trip, ['package_count' => 2]);
    shSave($this, $admin, $customer, $trip, ['clear' => 1])->assertSessionHas('success');

    $shipment = Shipment::first();
    expect($shipment->package_count)->toBeNull()->and($shipment->shipped_on)->toBeNull();

    // and the customer is back under "To ship"
    expect(shTab($this, $admin, $trip, ['ship' => 'pending'])->getContent())->toContain(e($customer->name));
});

test('removing a shipment never touches an unpaid customer', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $unpaid = shCustomer($this, $admin, $trip, 300000, 'unpaid');

    shSave($this, $admin, $unpaid, $trip, ['clear' => 1])->assertSessionHas('error');
    expect(Shipment::count())->toBe(0);
});

test('the saved message names the customer, and that row is highlighted on return', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $customer = shCustomer($this, $admin, $trip);

    $response = shSave($this, $admin, $customer, $trip, ['package_count' => 3]);
    $response->assertSessionHas('success', fn($m) => str_contains($m, $customer->name) && str_contains($m, '3 package'));
    $response->assertSessionHas('shipment_saved', $customer->id);
    expect($response->headers->get('Location'))->toEndWith('#ship-' . $customer->id);
});

test('a saved row is locked until Edit is pressed, a new row is open for typing', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $sent  = shCustomer($this, $admin, $trip);
    $new   = shCustomer($this, $admin, $trip);
    shSave($this, $admin, $sent, $trip, ['package_count' => 4, 'shipped_on' => '2026-10-15']);

    $html = shTab($this, $admin, $trip)->assertOk()->getContent();

    // the saved row: inputs disabled, a locked summary and an Edit button
    $sentRow = substr($html, strpos($html, 'id="ship-' . $sent->id . '"'));
    $sentRow = substr($sentRow, 0, strpos($sentRow, '</tr>'));
    expect($sentRow)->toContain('4 packages')->toContain('shipEdit(' . $sent->id . ')')
        ->and($sentRow)->toMatch('/name="package_count"[^>]*disabled/');

    // the new row: nothing locked, no Edit button
    $newRow = substr($html, strpos($html, 'id="ship-' . $new->id . '"'));
    $newRow = substr($newRow, 0, strpos($newRow, '</tr>'));
    expect($newRow)->not->toContain('shipEdit(')
        ->and($newRow)->not->toMatch('/name="package_count"[^>]*disabled/');
});

test('the package box is a text box for digits, so the mouse wheel cannot change it', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    shCustomer($this, $admin, $trip);

    $html = shTab($this, $admin, $trip)->getContent();
    expect($html)->toMatch('/<input type="text" name="package_count" inputmode="numeric"/')
        ->and($html)->not->toMatch('/<input type="number" name="package_count"/');
});

test('the All / To ship / Shipped buttons filter the list and show the counts', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $sent = shCustomer($this, $admin, $trip);
    $todo = shCustomer($this, $admin, $trip);
    shSave($this, $admin, $sent, $trip, ['package_count' => 1]);

    $all     = shTab($this, $admin, $trip)->getContent();
    $pending = shTab($this, $admin, $trip, ['ship' => 'pending'])->getContent();
    $shipped = shTab($this, $admin, $trip, ['ship' => 'shipped'])->getContent();

    expect($all)->toContain(e($sent->name))->toContain(e($todo->name))
        ->and($pending)->toContain(e($todo->name))->not->toContain(e($sent->name))
        ->and($shipped)->toContain(e($sent->name))->not->toContain(e($todo->name));

    // counts on the buttons: All 2, To ship 1, Shipped 1
    expect($all)->toMatch('/All\s*<span[^>]*>2<\/span>/')
        ->and($all)->toMatch('/To ship\s*<span[^>]*>1<\/span>/')
        ->and($all)->toMatch('/Shipped\s*<span[^>]*>1<\/span>/');
});

test('the filter buttons keep the search and the trip', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $customer = shCustomer($this, $admin, $trip);

    $html = shTab($this, $admin, $trip, ['search' => $customer->name])->getContent();
    expect($html)->toContain('ship=pending')->toContain('trip_id=' . $trip->id);
});

test('the saved package count and date show up on the tab', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $customer = shCustomer($this, $admin, $trip);

    shSave($this, $admin, $customer, $trip, ['package_count' => 5, 'shipped_on' => '2026-10-15']);

    $html = shTab($this, $admin, $trip)->assertOk()->getContent();
    expect($html)->toMatch('/name="package_count"[^>]*value="5"/')->toContain('value="2026-10-15"');
});

test('a customer who is not fully paid cannot be shipped, and nothing is saved', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $unpaid     = shCustomer($this, $admin, $trip, 300000, 'unpaid');
    $unverified = shCustomer($this, $admin, $trip, 410000, 'paid', 'unverified');

    shSave($this, $admin, $unpaid, $trip, ['package_count' => 2])->assertSessionHas('error');
    shSave($this, $admin, $unverified, $trip, ['package_count' => 2])->assertSessionHas('error');

    expect(Shipment::count())->toBe(0);
});

test('a package count must be a whole number from 1 up', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $customer = shCustomer($this, $admin, $trip);

    foreach (['0', '-2', 'abc', '2.5', '100000'] as $bad) {
        shSave($this, $admin, $customer, $trip, ['package_count' => $bad])->assertSessionHasErrors('package_count');
    }
    expect(Shipment::count())->toBe(0);
});

test('the shipment of one trip does not show on another trip', function () {
    $admin = $this->adminUser();
    $trip1 = $this->openTrip();
    $trip2 = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $customer = shCustomer($this, $admin, $trip1);
    // same customer, second trip
    $order = Order::factory()->create([
        'trip_id' => $trip2->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
        'subtotal' => 200000, 'total_amount' => 200000, 'deposit_paid' => 200000, 'payment_status' => 'paid',
    ]);
    Payment::factory()->create(['order_id' => $order->id, 'amount' => 200000, 'type' => 'deposit',
        'paid_at' => now(), 'voided_at' => null, 'verification_status' => 'verified']);

    shSave($this, $admin, $customer, $trip1, ['package_count' => 7, 'shipped_on' => '2026-10-15']);

    expect(shTab($this, $admin, $trip2)->assertOk()->getContent())
        ->not->toMatch('/name="package_count"[^>]*value="7"/')->not->toContain('2026-10-15');
    expect(shTab($this, $admin, $trip1)->assertOk()->getContent())
        ->toMatch('/name="package_count"[^>]*value="7"/');
});

test('the tab badge counts only fully paid customers that have not been sent yet', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $sent = shCustomer($this, $admin, $trip);
    shCustomer($this, $admin, $trip);
    shCustomer($this, $admin, $trip);
    shCustomer($this, $admin, $trip, 100000, 'unpaid');

    shSave($this, $admin, $sent, $trip, ['package_count' => 1]);

    $html = shTab($this, $admin, $trip)->assertOk()->getContent();
    expect($html)->toMatch('/Shipment\s*<span class="badge bg-success ms-1"[^>]*>2<\/span>/');
});

test('printing still works from the Shipment tab', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $customer = shCustomer($this, $admin, $trip);

    $this->actingAs($admin)->post(route('payments.mark-printed'), ['customer_id' => $customer->id, 'trip_id' => $trip->id])->assertRedirect();

    shTab($this, $admin, $trip)->assertOk()->assertSee('Printed');
    expect(Order::where('customer_id', $customer->id)->first()->invoice_printed_at)->not->toBeNull();
});
