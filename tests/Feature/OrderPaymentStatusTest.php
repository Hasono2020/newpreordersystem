<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ShippingArea;
use App\Models\Trip;
use App\Models\User;

/*
 * Fix #1 — recalcPaymentStatus() on the Order model
 *
 * Previously duplicated identically in OrderController, PaymentController,
 * and CreditReallocationService. These tests call the model method directly
 * to prove the single source of truth works in isolation.
 */

// ── Pure factory helpers (no $this — safe to call from anywhere) ──────

function makePaymentOrder(float $total = 500000): Order
{
    $admin = User::factory()->admin()->create();
    $trip  = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $area  = ShippingArea::factory()->create();
    $cust  = Customer::factory()->create([
        'default_shipping_area_id' => $area->id,
        'created_by'               => $admin->id,
    ]);
    return Order::factory()->create([
        'trip_id'          => $trip->id,
        'customer_id'      => $cust->id,
        'created_by'       => $admin->id,
        'shipping_area_id' => $area->id,
        'subtotal'         => $total,
        'total_amount'     => $total,
        'deposit_paid'     => 0,
        'payment_status'   => 'unpaid',
    ]);
}

function addPmt(Order $order, float $amount, string $type = 'partial', ?string $voidedAt = null): Payment
{
    return Payment::factory()->create([
        'order_id'    => $order->id,
        'amount'      => $amount,
        'type'        => $type,
        'voided_at'   => $voidedAt,
        'recorded_by' => User::factory()->admin()->create()->id,
    ]);
}

// ── Status transitions ───────────────────────────────────────────────

test('recalcPaymentStatus: no payments -> unpaid', function () {
    $order = makePaymentOrder(500000);
    $order->recalcPaymentStatus();
    expect($order->fresh()->payment_status)->toBe('unpaid')
        ->and((float) $order->fresh()->deposit_paid)->toBe(0.0);
});

test('recalcPaymentStatus: partial payment -> partial', function () {
    $order = makePaymentOrder(500000);
    addPmt($order, 200000);
    $order->recalcPaymentStatus();
    expect($order->fresh()->payment_status)->toBe('partial')
        ->and((float) $order->fresh()->deposit_paid)->toBe(200000.0);
});

test('recalcPaymentStatus: full payment -> paid', function () {
    $order = makePaymentOrder(500000);
    addPmt($order, 500000);
    $order->recalcPaymentStatus();
    expect($order->fresh()->payment_status)->toBe('paid')
        ->and((float) $order->fresh()->deposit_paid)->toBe(500000.0);
});

test('recalcPaymentStatus: overpayment -> paid with full deposited amount', function () {
    $order = makePaymentOrder(500000);
    addPmt($order, 600000);
    $order->recalcPaymentStatus();
    expect($order->fresh()->payment_status)->toBe('paid')
        ->and((float) $order->fresh()->deposit_paid)->toBe(600000.0);
});

// ── Refund sign logic ────────────────────────────────────────────────

test('recalcPaymentStatus: refund reduces paid amount', function () {
    $order = makePaymentOrder(500000);
    addPmt($order, 400000, 'partial');
    addPmt($order, 100000, 'refund');
    $order->recalcPaymentStatus();
    expect((float) $order->fresh()->deposit_paid)->toBe(300000.0)
        ->and($order->fresh()->payment_status)->toBe('partial');
});

test('recalcPaymentStatus: refund larger than payments clamps to zero', function () {
    $order = makePaymentOrder(500000);
    addPmt($order, 100000, 'partial');
    addPmt($order, 200000, 'refund');
    $order->recalcPaymentStatus();
    expect((float) $order->fresh()->deposit_paid)->toBe(0.0)
        ->and($order->fresh()->payment_status)->toBe('unpaid');
});

// ── Voided payments are excluded ─────────────────────────────────────

test('recalcPaymentStatus: voided payments do not count', function () {
    $order = makePaymentOrder(500000);
    addPmt($order, 300000, 'partial', now()->toDateTimeString()); // voided
    addPmt($order, 100000, 'partial');
    $order->recalcPaymentStatus();
    expect((float) $order->fresh()->deposit_paid)->toBe(100000.0)
        ->and($order->fresh()->payment_status)->toBe('partial');
});

test('recalcPaymentStatus: all payments voided reverts to unpaid', function () {
    $order = makePaymentOrder(500000);
    addPmt($order, 500000, 'partial', now()->toDateTimeString());
    $order->recalcPaymentStatus();
    expect($order->fresh()->payment_status)->toBe('unpaid')
        ->and((float) $order->fresh()->deposit_paid)->toBe(0.0);
});

// ── Auto-confirm pending items when fully paid ───────────────────────

test('recalcPaymentStatus: full payment auto-confirms pending items', function () {
    $order   = makePaymentOrder(300000);
    $product = Product::create([
        'trip_id'      => $order->trip_id,
        'product_code' => 'AA_01',
        'price'        => 300000,
        'weight_gram'  => 200,
        'status'       => 'active',
    ]);
    $item = OrderItem::create([
        'order_id'   => $order->id,
        'product_id' => $product->id,
        'quantity'   => 1,
        'unit_price' => 300000,
        'line_total' => 300000,
        'status'     => 'pending',
    ]);

    addPmt($order, 300000);
    $order->recalcPaymentStatus();

    expect($item->fresh()->status)->toBe('confirmed');
});

test('recalcPaymentStatus: voiding after full-pay does NOT revert confirmed items', function () {
    $order   = makePaymentOrder(300000);
    $product = Product::create([
        'trip_id'      => $order->trip_id,
        'product_code' => 'AA_02',
        'price'        => 300000,
        'weight_gram'  => 200,
        'status'       => 'active',
    ]);
    $item = OrderItem::create([
        'order_id'   => $order->id,
        'product_id' => $product->id,
        'quantity'   => 1,
        'unit_price' => 300000,
        'line_total' => 300000,
        'status'     => 'pending',
    ]);

    $payment = addPmt($order, 300000);
    $order->recalcPaymentStatus();
    expect($item->fresh()->status)->toBe('confirmed');

    // Void payment — order goes unpaid but item stays confirmed (intentional)
    $payment->update(['voided_at' => now()]);
    $order->refresh()->recalcPaymentStatus();

    expect($order->fresh()->payment_status)->toBe('unpaid')
        ->and($item->fresh()->status)->toBe('confirmed');
});