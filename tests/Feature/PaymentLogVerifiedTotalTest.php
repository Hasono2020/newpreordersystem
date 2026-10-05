<?php

use App\Models\Order;
use App\Models\Payment;

/*
 * Reported: the Payment Log's "Total Verified (Rp)" card showed 16.410.000
 * for a Rp 15.000.000 deposit with Rp 810.000 and Rp 600.000 refunded — it
 * was adding refunds to the deposits instead of subtracting them (15.000.000
 * + 810.000 + 600.000), even though the table right below lists them as
 * negative amounts.
 */

test('Total Verified subtracts refunds instead of adding them', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $order = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'order_number' => null]);

    Payment::create(['order_id' => $order->id, 'amount' => 100000, 'type' => 'deposit', 'method' => 'Transfer', 'paid_at' => now(), 'recorded_by' => $admin->id, 'verification_status' => 'verified']);
    Payment::create(['order_id' => $order->id, 'amount' => 30000, 'type' => 'refund', 'method' => 'Refund', 'paid_at' => now(), 'recorded_by' => $admin->id, 'verification_status' => 'verified']);

    $response = $this->actingAs($admin)->get(route('payments.index', ['trip_id' => $trip->id, 'tab' => 'log']));

    $response->assertOk();
    $response->assertSeeText('70.000');          // 100,000 received - 30,000 refunded
    $response->assertDontSeeText('130.000');     // what adding them would have shown
});

test('a voided refund no longer counts against Total Verified', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $order = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'order_number' => null]);

    Payment::create(['order_id' => $order->id, 'amount' => 100000, 'type' => 'deposit', 'method' => 'Transfer', 'paid_at' => now(), 'recorded_by' => $admin->id, 'verification_status' => 'verified']);
    Payment::create(['order_id' => $order->id, 'amount' => 30000, 'type' => 'refund', 'method' => 'Refund', 'paid_at' => now(), 'recorded_by' => $admin->id, 'verification_status' => 'verified', 'voided_at' => now(), 'voided_by' => $admin->id]);

    $response = $this->actingAs($admin)->get(route('payments.index', ['trip_id' => $trip->id, 'tab' => 'log']));

    $response->assertOk();
    $response->assertDontSeeText('70.000'); // the refund was voided, so nothing is subtracted
});
