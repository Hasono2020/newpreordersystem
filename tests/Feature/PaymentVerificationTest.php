<?php
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;

// ── Helper ────────────────────────────────────────────────────────────
function makePaymentFor(Tests\TestCase $t): Payment
{
    $trip    = $t->openTrip();
    $area    = $t->shippingArea();
    $staff   = $t->staffUser();
    $cust    = $t->customer($staff);
    $order   = Order::factory()->create([
        'trip_id'        => $trip->id,
        'customer_id'    => $cust->id,
        'created_by'     => $staff->id,
        'shipping_area_id' => $area->id,
    ]);
    return Payment::factory()->create([
        'order_id'    => $order->id,
        'recorded_by' => $staff->id,
    ]);
}

test('new payment starts as unverified', function () {
    $payment = makePaymentFor($this);
    expect($payment->verification_status)->toBe('unverified')
        ->and($payment->isUnverified())->toBeTrue()
        ->and($payment->isVerified())->toBeFalse();
});

test('finance can verify a payment', function () {
    $finance = $this->financeUser();
    $payment = makePaymentFor($this);

    $this->actingAs($finance)
         ->post("/payments/{$payment->id}/verify")
         ->assertRedirect();

    expect($payment->fresh()->verification_status)->toBe('verified')
        ->and($payment->fresh()->verified_by)->toBe($finance->id);
});

test('staff cannot verify a payment', function () {
    $staff   = $this->staffUser();
    $payment = makePaymentFor($this);

    $this->actingAs($staff)
         ->post("/payments/{$payment->id}/verify")
         ->assertStatus(403);

    expect($payment->fresh()->verification_status)->toBe('unverified');
});

test('finance can dispute a payment with a reason', function () {
    $finance = $this->financeUser();
    $payment = makePaymentFor($this);

    $this->actingAs($finance)
         ->post("/payments/{$payment->id}/dispute", [
             'dispute_note' => 'Bank shows Rp 200k not Rp 400k.',
         ])
         ->assertRedirect();

    $fresh = $payment->fresh();
    expect($fresh->verification_status)->toBe('disputed')
        ->and($fresh->dispute_note)->toBe('Bank shows Rp 200k not Rp 400k.');
});

test('dispute requires a reason', function () {
    $finance = $this->financeUser();
    $payment = makePaymentFor($this);

    $this->actingAs($finance)
         ->post("/payments/{$payment->id}/dispute", ['dispute_note' => ''])
         ->assertSessionHasErrors('dispute_note');

    expect($payment->fresh()->verification_status)->toBe('unverified');
});

test('voided payment cannot be verified', function () {
    $finance = $this->financeUser();
    $payment = makePaymentFor($this);
    $payment->update(['voided_at' => now(), 'void_reason' => 'test']);

    $this->actingAs($finance)
         ->post("/payments/{$payment->id}/verify")
         ->assertRedirect();

    // Should redirect with error, not change status
    expect($payment->fresh()->verification_status)->toBe('unverified');
});

test('payment log shows only own orders for own_data staff', function () {
    $staffA  = $this->ownDataStaff();
    $staffB  = $this->ownDataStaff();
    $trip    = $this->openTrip();
    $area    = $this->shippingArea();
    $custA   = $this->customer($staffA);
    $custB   = $this->customer($staffB);

    $orderA = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $custA->id, 'created_by' => $staffA->id, 'shipping_area_id' => $area->id]);
    $orderB = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $custB->id, 'created_by' => $staffB->id, 'shipping_area_id' => $area->id]);

    Payment::factory()->create(['order_id' => $orderA->id, 'recorded_by' => $staffA->id]);
    Payment::factory()->create(['order_id' => $orderB->id, 'recorded_by' => $staffB->id]);

    $this->actingAs($staffA)
         ->get("/payments?trip_id={$trip->id}&tab=log")
         ->assertSee($custA->name)
         ->assertDontSee($custB->name);
});
