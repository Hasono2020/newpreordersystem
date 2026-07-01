<?php

use App\Models\Order;
use App\Models\Payment;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;

/*
 * These tests lock in the payment balance + status logic in
 * OrderController::recalcOrderPayment (driven through the HTTP endpoints,
 * since the method is private) and PaymentController verify/void.
 *
 * Reference logic (recalcOrderPayment):
 *   paid = sum(non-refund, non-voided amounts) - sum(refund, non-voided amounts)
 *   status = paid <= 0 ? unpaid : (paid >= total ? paid : partial)
 *   when status becomes 'paid', pending items auto-confirm
 *   voiding does NOT revert already-confirmed items
 */

// ── Helpers ──────────────────────────────────────────────────────────

function paymentOrder($test, float $total = 1000000, ?User $by = null): Order
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

// ── Recording payments: balance + status ─────────────────────────────

test('recording a partial payment sets status to partial', function () {
    $admin = $this->adminUser();
    $order = paymentOrder($this, 1000000, $admin);

    $this->actingAs($admin)->post("/orders/{$order->id}/payments", [
        'amount' => 400000, 'type' => 'partial', 'paid_at' => now()->toDateString(),
    ])->assertRedirect();

    $order->refresh();
    expect((float) $order->deposit_paid)->toBe(400000.0)
        ->and($order->payment_status)->toBe('partial');
});

test('paying the exact total marks the order fully paid', function () {
    $admin = $this->adminUser();
    $order = paymentOrder($this, 1000000, $admin);

    $this->actingAs($admin)->post("/orders/{$order->id}/payments", [
        'amount' => 1000000, 'type' => 'full', 'paid_at' => now()->toDateString(),
    ])->assertRedirect();

    $order->refresh();
    expect((float) $order->deposit_paid)->toBe(1000000.0)
        ->and($order->payment_status)->toBe('paid');
});

test('overpaying still marks paid and records the full amount', function () {
    $admin = $this->adminUser();
    $order = paymentOrder($this, 1000000, $admin);

    $this->actingAs($admin)->post("/orders/{$order->id}/payments", [
        'amount' => 1200000, 'type' => 'full', 'paid_at' => now()->toDateString(),
    ])->assertRedirect();

    $order->refresh();
    // paid (1.2M) >= total (1M) -> paid; deposit_paid reflects the full 1.2M
    expect((float) $order->deposit_paid)->toBe(1200000.0)
        ->and($order->payment_status)->toBe('paid');
});

// ── Voiding: balance restoration + status revert ─────────────────────

test('voiding the only payment reverts the order to unpaid', function () {
    $admin = $this->adminUser();
    $order = paymentOrder($this, 1000000, $admin);

    $this->actingAs($admin)->post("/orders/{$order->id}/payments", [
        'amount' => 400000, 'type' => 'partial', 'paid_at' => now()->toDateString(),
    ]);
    $payment = $order->payments()->first();

    $this->actingAs($admin)->post("/payments/{$payment->id}/void", [
        'void_reason' => 'Entered by mistake',
    ])->assertRedirect();

    $order->refresh();
    expect((float) $order->deposit_paid)->toBe(0.0)
        ->and($order->payment_status)->toBe('unpaid')
        ->and($payment->fresh()->isVoided())->toBeTrue();
});

test('voiding one of several payments only removes that amount', function () {
    $admin = $this->adminUser();
    $order = paymentOrder($this, 1000000, $admin);

    $this->actingAs($admin)->post("/orders/{$order->id}/payments", ['amount' => 300000, 'type' => 'partial', 'paid_at' => now()->toDateString()]);
    $this->actingAs($admin)->post("/orders/{$order->id}/payments", ['amount' => 200000, 'type' => 'partial', 'paid_at' => now()->toDateString()]);

    $first = $order->payments()->orderBy('id')->first();

    $this->actingAs($admin)->post("/payments/{$first->id}/void", ['void_reason' => 'duplicate']);

    $order->refresh();
    // 500000 paid, void 300000 -> 200000 remains, still partial
    expect((float) $order->deposit_paid)->toBe(200000.0)
        ->and($order->payment_status)->toBe('partial');
});

test('voiding an already-voided payment is blocked', function () {
    $admin = $this->adminUser();
    $order = paymentOrder($this, 1000000, $admin);
    $this->actingAs($admin)->post("/orders/{$order->id}/payments", ['amount' => 400000, 'type' => 'partial', 'paid_at' => now()->toDateString()]);
    $payment = $order->payments()->first();

    $this->actingAs($admin)->post("/payments/{$payment->id}/void", ['void_reason' => 'first void']);
    // Second void attempt should not change anything / not double-apply
    $this->actingAs($admin)->post("/payments/{$payment->id}/void", ['void_reason' => 'second void']);

    $order->refresh();
    expect((float) $order->deposit_paid)->toBe(0.0)
        ->and($order->payment_status)->toBe('unpaid');
});

// ── Refund sign logic ────────────────────────────────────────────────

test('a refund reduces the paid amount', function () {
    $admin = $this->adminUser();
    $order = paymentOrder($this, 1000000, $admin);

    $this->actingAs($admin)->post("/orders/{$order->id}/payments", ['amount' => 600000, 'type' => 'partial', 'paid_at' => now()->toDateString()]);
    $this->actingAs($admin)->post("/orders/{$order->id}/payments", ['amount' => 100000, 'type' => 'refund', 'paid_at' => now()->toDateString()]);

    $order->refresh();
    // 600000 paid - 100000 refund = 500000
    expect((float) $order->deposit_paid)->toBe(500000.0)
        ->and($order->payment_status)->toBe('partial');
});

test('voiding a refund adds the refunded amount back', function () {
    $admin = $this->adminUser();
    $order = paymentOrder($this, 1000000, $admin);

    $this->actingAs($admin)->post("/orders/{$order->id}/payments", ['amount' => 600000, 'type' => 'partial', 'paid_at' => now()->toDateString()]);
    $this->actingAs($admin)->post("/orders/{$order->id}/payments", ['amount' => 100000, 'type' => 'refund', 'paid_at' => now()->toDateString()]);

    $refund = $order->payments()->where('type', 'refund')->first();
    $this->actingAs($admin)->post("/payments/{$refund->id}/void", ['void_reason' => 'refund reversed']);

    $order->refresh();
    // refund voided -> back to just the 600000 payment
    expect((float) $order->deposit_paid)->toBe(600000.0);
});

// ── Item auto-confirm behaviour ──────────────────────────────────────

test('fully paying auto-confirms pending items, and voiding does not revert them', function () {
    $admin = $this->adminUser();
    $by    = $admin;
    $trip  = $this->openTrip();
    $area  = $this->shippingArea();
    $cust  = $this->customer($by);

    $order = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $cust->id, 'created_by' => $by->id,
        'shipping_area_id' => $area->id,
        'subtotal' => 500000, 'total_amount' => 500000,
        'deposit_paid' => 0, 'payment_status' => 'unpaid',
    ]);
    $product = Product::create([
        'trip_id' => $trip->id, 'product_code' => 'AA_01',
        'price' => 500000, 'weight_gram' => 200, 'status' => 'active',
    ]);
    $item = OrderItem::create([
        'order_id' => $order->id, 'product_id' => $product->id,
        'quantity' => 1, 'unit_price' => 500000, 'line_total' => 500000,
        'status' => 'pending',
    ]);

    // Pay in full -> pending item should become confirmed
    $this->actingAs($admin)->post("/orders/{$order->id}/payments", [
        'amount' => 500000, 'type' => 'full', 'paid_at' => now()->toDateString(),
    ]);
    expect($item->fresh()->status)->toBe('confirmed');

    // Void the payment -> order reverts to unpaid, but item stays confirmed (intentional)
    $payment = $order->payments()->first();
    $this->actingAs($admin)->post("/payments/{$payment->id}/void", ['void_reason' => 'mistake']);

    $order->refresh();
    expect($order->payment_status)->toBe('unpaid')
        ->and($item->fresh()->status)->toBe('confirmed'); // NOT reverted
});

// ── Verify / batch verify ────────────────────────────────────────────

test('finance can verify a payment and it records who verified', function () {
    $finance = $this->financeUser();
    $admin   = $this->adminUser();
    $order   = paymentOrder($this, 1000000, $admin);
    $this->actingAs($admin)->post("/orders/{$order->id}/payments", ['amount' => 400000, 'type' => 'partial', 'paid_at' => now()->toDateString()]);
    $payment = $order->payments()->first();

    $this->actingAs($finance)->post("/payments/{$payment->id}/verify")->assertRedirect();

    $fresh = $payment->fresh();
    expect($fresh->verification_status)->toBe('verified')
        ->and($fresh->verified_by)->toBe($finance->id);
});

test('a voided payment cannot be verified', function () {
    $finance = $this->financeUser();
    $admin   = $this->adminUser();
    $order   = paymentOrder($this, 1000000, $admin);
    $this->actingAs($admin)->post("/orders/{$order->id}/payments", ['amount' => 400000, 'type' => 'partial', 'paid_at' => now()->toDateString()]);
    $payment = $order->payments()->first();
    $this->actingAs($admin)->post("/payments/{$payment->id}/void", ['void_reason' => 'mistake']);

    $this->actingAs($finance)->post("/payments/{$payment->id}/verify")->assertRedirect();

    // Still unverified — verify must refuse a voided payment
    expect($payment->fresh()->verification_status)->toBe('unverified');
});

test('batch verify flips all payments in the batch to verified', function () {
    $finance = $this->financeUser();
    $admin   = $this->adminUser();
    $trip    = $this->openTrip();
    $area    = $this->shippingArea();
    $cust    = $this->customer($admin);

    // Two orders for one customer, paid together as a batch
    $o1 = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $cust->id, 'created_by' => $admin->id, 'shipping_area_id' => $area->id, 'subtotal' => 500000, 'total_amount' => 500000]);
    $o2 = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $cust->id, 'created_by' => $admin->id, 'shipping_area_id' => $area->id, 'subtotal' => 500000, 'total_amount' => 500000]);

    $batchId = (string) \Illuminate\Support\Str::uuid();
    $p1 = Payment::factory()->create(['order_id' => $o1->id, 'recorded_by' => $admin->id, 'batch_id' => $batchId, 'amount' => 500000, 'verification_status' => 'unverified']);
    $p2 = Payment::factory()->create(['order_id' => $o2->id, 'recorded_by' => $admin->id, 'batch_id' => $batchId, 'amount' => 500000, 'verification_status' => 'unverified']);

    $this->actingAs($finance)->post("/payments/batch/{$batchId}/verify")->assertRedirect();

    expect($p1->fresh()->verification_status)->toBe('verified')
        ->and($p2->fresh()->verification_status)->toBe('verified');
});

test('batch void restores balances on all affected orders', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $area  = $this->shippingArea();
    $cust  = $this->customer($admin);

    $o1 = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $cust->id, 'created_by' => $admin->id, 'shipping_area_id' => $area->id, 'subtotal' => 500000, 'total_amount' => 500000, 'deposit_paid' => 500000, 'payment_status' => 'paid']);
    $o2 = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $cust->id, 'created_by' => $admin->id, 'shipping_area_id' => $area->id, 'subtotal' => 300000, 'total_amount' => 300000, 'deposit_paid' => 300000, 'payment_status' => 'paid']);

    $batchId = (string) \Illuminate\Support\Str::uuid();
    Payment::factory()->create(['order_id' => $o1->id, 'recorded_by' => $admin->id, 'batch_id' => $batchId, 'amount' => 500000, 'type' => 'partial']);
    Payment::factory()->create(['order_id' => $o2->id, 'recorded_by' => $admin->id, 'batch_id' => $batchId, 'amount' => 300000, 'type' => 'partial']);

    $this->actingAs($admin)->post("/payments/batch/{$batchId}/void", ['void_reason' => 'wrong batch'])->assertRedirect();

    expect($o1->fresh()->payment_status)->toBe('unpaid')
        ->and((float) $o1->fresh()->deposit_paid)->toBe(0.0)
        ->and($o2->fresh()->payment_status)->toBe('unpaid')
        ->and((float) $o2->fresh()->deposit_paid)->toBe(0.0);
});