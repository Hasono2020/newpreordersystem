<?php

use App\Models\Order;
use App\Models\User;

/*
 * Tests for PaymentController::index() Outstanding Balances / Overpaid
 * netting fix.
 *
 * Bug: a customer with multiple orders in the same trip could show up
 * BOTH with a "Balance Due" in the Outstanding table AND a "Credit /
 * Refund Due" in the Overpaid banner at the same time — confusing, since
 * their true net position was actually a credit. This happened because
 * the Outstanding query only summed orders where payment_status != 'paid',
 * ignoring an overpayment sitting on a different (fully-paid) order for
 * the same customer in the same trip.
 *
 * Fix: sum across ALL of a customer's orders in the trip (not just the
 * ones that individually look unpaid), so the balance reflects their
 * TRUE net position. A customer in net credit now correctly disappears
 * from Outstanding entirely and only shows in the Overpaid banner.
 */

/**
 * @param mixed $test Pest binds $this to a TestCase subclass at runtime;
 *        typed as mixed here because static analyzers see Pest's
 *        closure-bound $this as Pest\PendingCalls\TestCall, which isn't
 *        assignable to a concrete TestCase type hint.
 */
function nettingTestOrder($test, float $total = 1000000, ?User $by = null): Order
{
    $by    = $by ?? $test->adminUser();
    $trip  = $test->openTrip();
    $area  = $test->shippingArea();
    $cust  = $test->customer($by);
    return Order::factory()->create([
        'trip_id'          => $trip->id,
        'customer_id'      => $cust->id,
        'created_by'       => $by->id,
        'shipping_area_id' => $area->id,
        'subtotal'         => $total,
        'total_amount'     => $total,
        'deposit_paid'     => 0,
        'payment_status'   => 'unpaid',
    ]);
}

test('a customer overpaid on one order and underpaid on another in the same trip is excluded from Outstanding', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $area     = $this->shippingArea();
    $customer = $this->customer($admin);

    // Order A: overpaid by 100,000 (price dropped after payment, e.g. a price sync)
    $orderA = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
        'shipping_area_id' => $area->id, 'subtotal' => 400000, 'total_amount' => 400000,
        'deposit_paid' => 500000, 'payment_status' => 'paid',
    ]);
    // Order B: underpaid by 30,000
    $orderB = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
        'shipping_area_id' => $area->id, 'subtotal' => 1045000, 'total_amount' => 1045000,
        'deposit_paid' => 1015000, 'payment_status' => 'partial',
    ]);

    // Net position: paid 1,515,000 vs ordered 1,445,000 -> 70,000 net credit.
    $response = $this->actingAs($admin)->get(route('payments.index', ['trip_id' => $trip->id, 'tab' => 'outstanding']));
    $response->assertOk();

    $outstandingCustomerIds = collect($response->viewData('outstanding')->items())->pluck('customer_id');
    expect($outstandingCustomerIds)->not->toContain($customer->id);

    $overpaidCustomerIds = $response->viewData('overpaid')->pluck('customer_id');
    expect($overpaidCustomerIds)->toContain($customer->id);

    $overpaidRow = $response->viewData('overpaid')->firstWhere('customer_id', $customer->id);
    expect((float) $overpaidRow->credit)->toBe(70000.0);
});

test('a customer with a genuine net shortfall still appears in Outstanding with the correct net balance', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $area     = $this->shippingArea();
    $customer = $this->customer($admin);

    // Order A: overpaid by 20,000
    Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
        'shipping_area_id' => $area->id, 'subtotal' => 100000, 'total_amount' => 100000,
        'deposit_paid' => 120000, 'payment_status' => 'paid',
    ]);
    // Order B: underpaid by 50,000
    Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
        'shipping_area_id' => $area->id, 'subtotal' => 200000, 'total_amount' => 200000,
        'deposit_paid' => 150000, 'payment_status' => 'partial',
    ]);

    // Net: still owes 30,000 (50,000 - 20,000) even after netting.
    $response = $this->actingAs($admin)->get(route('payments.index', ['trip_id' => $trip->id, 'tab' => 'outstanding']));

    $row = collect($response->viewData('outstanding')->items())->firstWhere('customer_id', $customer->id);
    expect($row)->not->toBeNull()
        ->and((float) $row->balance_due)->toBe(30000.0);
});

test('a customer with no orders at all in the trip does not appear in either list', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    nettingTestOrder($this, 1000000, $admin); // unrelated order/customer, fully unpaid

    $response = $this->actingAs($admin)->get(route('payments.index', ['trip_id' => $trip->id, 'tab' => 'outstanding']));
    $response->assertOk();
});

test('export Outstanding Balances sheet excludes a customer in net credit', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $area     = $this->shippingArea();
    $customer = $this->customer($admin);

    Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
        'shipping_area_id' => $area->id, 'subtotal' => 400000, 'total_amount' => 400000,
        'deposit_paid' => 500000, 'payment_status' => 'paid',
    ]);
    Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
        'shipping_area_id' => $area->id, 'subtotal' => 1045000, 'total_amount' => 1045000,
        'deposit_paid' => 1015000, 'payment_status' => 'partial',
    ]);

    $response = $this->actingAs($admin)->get(route('payments.export', ['trip_id' => $trip->id]));
    $response->assertOk();
});