<?php

use App\Models\Order;
use App\Models\Trip;

/*
 * New order number format: ORD/B{batch}/{month}/{6-digit sequence},
 * strictly sequential and resetting to 000001 per trip (batch). Only
 * applies to trips with a batch_number set — trips without one (all
 * existing trips, since this doesn't apply retroactively) keep the old
 * random ORD-xxxxxxxx format, completely unaffected.
 */

test('a trip without a batch_number still gets the old random order number format', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip(); // batch_number null by default
    $customer = $this->customer($admin);

    $order = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'order_number' => null,
    ]);

    expect($order->order_number)->toStartWith('ORD-');
    expect($order->order_number)->not->toContain('/');
});

test('a trip with a batch_number produces the new sequential format, starting at 000001', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $trip->update(['batch_number' => 17]);
    $customer = $this->customer($admin);

    $order = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'order_number' => null,
    ]);

    $month = now()->format('m');
    expect($order->order_number)->toBe("ORD/B17/{$month}/000001");
});

test('sequential numbers increment correctly across multiple orders on the same trip', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $trip->update(['batch_number' => 20]);
    $customer = $this->customer($admin);

    $orders = collect(range(1, 3))->map(fn () => Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'order_number' => null,
    ]));

    $month = now()->format('m');
    expect($orders[0]->order_number)->toBe("ORD/B20/{$month}/000001");
    expect($orders[1]->order_number)->toBe("ORD/B20/{$month}/000002");
    expect($orders[2]->order_number)->toBe("ORD/B20/{$month}/000003");

    // No duplicates, ever.
    expect($orders->pluck('order_number')->unique())->toHaveCount(3);
});

test('two different trips (different batches) number independently, each starting at 000001', function () {
    $admin    = $this->adminUser();
    $tripA    = $this->openTrip();
    $tripA->update(['batch_number' => 21]);
    $tripB    = $this->openTrip();
    $tripB->update(['batch_number' => 22]);
    $customer = $this->customer($admin);

    $orderA = Order::factory()->create(['trip_id' => $tripA->id, 'customer_id' => $customer->id, 'order_number' => null]);
    $orderB1 = Order::factory()->create(['trip_id' => $tripB->id, 'customer_id' => $customer->id, 'order_number' => null]);
    $orderA2 = Order::factory()->create(['trip_id' => $tripA->id, 'customer_id' => $customer->id, 'order_number' => null]);

    $month = now()->format('m');
    expect($orderA->order_number)->toBe("ORD/B21/{$month}/000001");
    expect($orderB1->order_number)->toBe("ORD/B22/{$month}/000001"); // independent sequence
    expect($orderA2->order_number)->toBe("ORD/B21/{$month}/000002"); // continues tripA's own count
});

test('reserving a block of numbers for an import continues correctly after manually-created orders', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $trip->update(['batch_number' => 30]);
    $customer = $this->customer($admin);

    // Two manual orders first.
    Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'order_number' => null]);
    Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'order_number' => null]);

    // Then a block reservation, as the importer does for a batch of rows.
    $numbers = Order::reserveOrderNumbers($trip->fresh(), 3);

    $month = now()->format('m');
    expect($numbers)->toBe([
        "ORD/B30/{$month}/000003",
        "ORD/B30/{$month}/000004",
        "ORD/B30/{$month}/000005",
    ]);
});

test('creating a second trip with an already-used batch number is rejected', function () {
    $admin = $this->adminUser();
    $this->actingAs($admin)->post(route('trips.store'), ['name' => 'Trip A', 'batch_number' => 40]);

    $response = $this->actingAs($admin)->post(route('trips.store'), ['name' => 'Trip B', 'batch_number' => 40]);

    $response->assertSessionHasErrors('batch_number');
    expect(Trip::where('batch_number', 40)->count())->toBe(1);
});

test('batch_number can still be set on a trip that has no orders yet', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip(); // next_order_seq = 0, no orders

    $response = $this->actingAs($admin)->put(route('trips.update', $trip), [
        'name' => $trip->name, 'status' => 'open', 'batch_number' => 50,
    ]);

    $response->assertRedirect();
    expect($trip->fresh()->batch_number)->toBe(50);
});

test('batch_number cannot be changed once orders already exist under it', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $trip->update(['batch_number' => 60]);
    $customer = $this->customer($admin);
    Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'order_number' => null]);

    $response = $this->actingAs($admin)->put(route('trips.update', $trip), [
        'name' => $trip->name, 'status' => 'open', 'batch_number' => 61,
    ]);

    $response->assertSessionHas('error');
    expect($trip->fresh()->batch_number)->toBe(60); // unchanged
});
