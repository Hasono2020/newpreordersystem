<?php

use App\Models\Order;
use App\Models\ShippingArea;
use App\Models\Trip;

/*
 * Tests for PaymentController::createForCustomer() (the "Record Payment"
 * screen). Reported symptom: Outstanding Balances showed a customer owing
 * Rp1,390,000 net, but opening Record Payment for the same customer/trip
 * showed Rp1,550,000 - a Rp160,000 gap matching exactly the credit
 * sitting on one of their orders that had been overpaid.
 *
 * Root cause: $totalDue summed each order's balance clamped to
 * max(0, total - paid) before adding them up, so a negative balance
 * (overpayment) contributed 0 instead of offsetting the other orders'
 * shortfalls. The order list itself was also filtered by the
 * payment_status STRING column rather than the actual paid-vs-total
 * numbers, so a stale status could leave an overpaid order sitting in
 * the allocation list with its balance silently zeroed out instead of
 * being excluded.
 */

function payScreenOrder($test, Trip $trip, ShippingArea $area, $customer, float $total, float $paid, ?string $orderedAt = null): Order
{
    return Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $test->adminUser()->id,
        'shipping_area_id' => $area->id, 'subtotal' => $total, 'total_amount' => $total,
        'deposit_paid' => $paid, 'payment_status' => $paid >= $total ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid'),
        'ordered_at' => $orderedAt ?? now(),
    ]);
}

test('totalDue nets an overpayment on one order against shortfalls on others', function () {
    $admin    = $this->adminUser();
    $area     = $this->shippingArea();
    $trip     = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $customer = $this->customer($admin);

    // Matches the reported scenario exactly.
    payScreenOrder($this, $trip, $area, $customer, 340000, 500000); // overpaid by 160,000
    payScreenOrder($this, $trip, $area, $customer, 350000, 0);
    payScreenOrder($this, $trip, $area, $customer, 350000, 0);
    payScreenOrder($this, $trip, $area, $customer, 350000, 0);
    payScreenOrder($this, $trip, $area, $customer, 500000, 0);

    $response = $this->actingAs($admin)->get(route('payments.create', ['customer' => $customer->id, 'trip_id' => $trip->id]));
    $response->assertOk();

    // 1,550,000 true shortfall - 160,000 available credit = 1,390,000 net.
    expect($response->viewData('totalDue'))->toBe(1390000.0);
});

test('an overpaid order is excluded from the allocation list even when its status label is stale', function () {
    $admin    = $this->adminUser();
    $area     = $this->shippingArea();
    $trip     = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $customer = $this->customer($admin);

    // Deliberately stale: numbers say overpaid, but the status column
    // still reads 'partial' — exactly what the screenshots showed, and
    // exactly the case a payment_status-based filter would miss.
    $overpaid = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
        'shipping_area_id' => $area->id, 'subtotal' => 340000, 'total_amount' => 340000,
        'deposit_paid' => 500000, 'payment_status' => 'partial',
    ]);
    $shortfall = payScreenOrder($this, $trip, $area, $customer, 350000, 0);

    $response = $this->actingAs($admin)->get(route('payments.create', ['customer' => $customer->id, 'trip_id' => $trip->id]));

    $orderIds = $response->viewData('orders')->pluck('id');
    expect($orderIds)->not->toContain($overpaid->id)
        ->and($orderIds)->toContain($shortfall->id);
});

test('strandedCredit reports the gap between the clamped sum and the true net', function () {
    $admin    = $this->adminUser();
    $area     = $this->shippingArea();
    $trip     = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $customer = $this->customer($admin);

    payScreenOrder($this, $trip, $area, $customer, 340000, 500000);
    payScreenOrder($this, $trip, $area, $customer, 350000, 0);

    $response = $this->actingAs($admin)->get(route('payments.create', ['customer' => $customer->id, 'trip_id' => $trip->id]));

    expect($response->viewData('strandedCredit'))->toBe(160000.0);
});

test('strandedCredit is zero when there is no overpaid order', function () {
    $admin    = $this->adminUser();
    $area     = $this->shippingArea();
    $trip     = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $customer = $this->customer($admin);

    payScreenOrder($this, $trip, $area, $customer, 350000, 100000);
    payScreenOrder($this, $trip, $area, $customer, 350000, 0);

    $response = $this->actingAs($admin)->get(route('payments.create', ['customer' => $customer->id, 'trip_id' => $trip->id]));

    expect($response->viewData('strandedCredit'))->toBe(0.0)
        ->and($response->viewData('totalDue'))->toBe(600000.0); // (350-100) + 350
});

test('totalDue matches a customer with no overpayment at all (simple case still works)', function () {
    $admin    = $this->adminUser();
    $area     = $this->shippingArea();
    $trip     = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $customer = $this->customer($admin);

    payScreenOrder($this, $trip, $area, $customer, 400000, 0);

    $response = $this->actingAs($admin)->get(route('payments.create', ['customer' => $customer->id, 'trip_id' => $trip->id]));

    expect($response->viewData('totalDue'))->toBe(400000.0)
        ->and($response->viewData('orders'))->toHaveCount(1);
});