<?php

use App\Models\Order;
use App\Models\Payment;
use App\Models\ShippingArea;
use App\Models\Trip;

/*
 * Tests for the `payments:reallocate-credit` catch-up command — fixes
 * overpay/underpay splits that existed before CreditReallocationService
 * was wired into the price-sync flow.
 */

test('reallocates historical credit for a specific trip', function () {
    $admin = $this->adminUser();
    $area  = $this->shippingArea();
    $trip  = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $customer = $this->customer($admin);

    $overpaid = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
        'shipping_area_id' => $area->id, 'subtotal' => 400000, 'total_amount' => 400000,
        'deposit_paid' => 500000, 'payment_status' => 'paid',
    ]);
    Payment::factory()->create(['order_id' => $overpaid->id, 'amount' => 500000, 'type' => 'deposit', 'paid_at' => now(), 'voided_at' => null]);

    $underpaid = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
        'shipping_area_id' => $area->id, 'subtotal' => 1045000, 'total_amount' => 1045000,
        'deposit_paid' => 1015000, 'payment_status' => 'partial',
    ]);
    Payment::factory()->create(['order_id' => $underpaid->id, 'amount' => 1015000, 'type' => 'deposit', 'paid_at' => now(), 'voided_at' => null]);

    $this->artisan('payments:reallocate-credit', ['--trip' => $trip->id])
        ->assertSuccessful();

    expect($underpaid->fresh()->payment_status)->toBe('paid');
});

test('requires either --trip or --all', function () {
    $this->artisan('payments:reallocate-credit')->assertFailed();
});

test('is safe to run twice with no duplicate reallocation', function () {
    $admin = $this->adminUser();
    $area  = $this->shippingArea();
    $trip  = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $customer = $this->customer($admin);

    $overpaid = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
        'shipping_area_id' => $area->id, 'subtotal' => 400000, 'total_amount' => 400000,
        'deposit_paid' => 500000, 'payment_status' => 'paid',
    ]);
    Payment::factory()->create(['order_id' => $overpaid->id, 'amount' => 500000, 'type' => 'deposit', 'paid_at' => now(), 'voided_at' => null]);

    $underpaid = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
        'shipping_area_id' => $area->id, 'subtotal' => 500000, 'total_amount' => 500000,
        'deposit_paid' => 400000, 'payment_status' => 'partial',
    ]);
    Payment::factory()->create(['order_id' => $underpaid->id, 'amount' => 400000, 'type' => 'deposit', 'paid_at' => now(), 'voided_at' => null]);

    $this->artisan('payments:reallocate-credit', ['--trip' => $trip->id]);
    $countAfterFirst = Payment::count();

    $this->artisan('payments:reallocate-credit', ['--trip' => $trip->id]);
    $countAfterSecond = Payment::count();

    expect($countAfterSecond)->toBe($countAfterFirst);
});
