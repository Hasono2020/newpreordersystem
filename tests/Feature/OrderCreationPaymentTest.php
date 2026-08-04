<?php

use App\Models\ActivityLog;
use App\Models\CsAgent;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;

function orderCreatePayload(Product $product, CsAgent $agent, int $tripId, int $customerId, array $extra = []): array
{
    return array_merge([
        'trip_id'     => $tripId,
        'customer_id' => $customerId,
        'cs_agent_id' => $agent->id,
        'items'       => [
            ['product_id' => $product->id, 'product_variant_id' => null, 'quantity' => 1, 'unit_price' => 100000],
        ],
        'client_token' => 'create-payment-token-' . uniqid(),
    ], $extra);
}

test('creating an order with a payment amount records the payment atomically with the order', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $agent    = CsAgent::factory()->create();
    $product  = Product::create([
        'trip_id' => $trip->id, 'product_code' => 'PAYCREATE01',
        'price' => 100000, 'weight_gram' => 200, 'status' => 'active',
    ]);

    $response = $this->actingAs($admin)->post(route('orders.store'), orderCreatePayload($product, $agent, $trip->id, $customer->id, [
        'payment_amount'    => 30000,
        'payment_type'      => 'deposit',
        'payment_reference' => 'TF#001',
        'payment_paid_at'   => '2026-08-04',
    ]));

    $response->assertRedirect();
    $order = Order::first();
    expect($order)->not->toBeNull();
    expect(Payment::where('order_id', $order->id)->count())->toBe(1);

    $payment = Payment::where('order_id', $order->id)->first();
    expect((float) $payment->amount)->toBe(30000.0)
        ->and($payment->type)->toBe('deposit')
        ->and($payment->reference)->toBe('TF#001');

    expect((float) $order->fresh()->deposit_paid)->toBe(30000.0);
    expect(ActivityLog::where('action', 'payment.recorded')->where('subject_id', $order->id)->count())->toBe(1);
});

test('creating an order without a payment amount creates no payment record', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $agent    = CsAgent::factory()->create();
    $product  = Product::create([
        'trip_id' => $trip->id, 'product_code' => 'PAYCREATE02',
        'price' => 100000, 'weight_gram' => 200, 'status' => 'active',
    ]);

    $this->actingAs($admin)->post(route('orders.store'), orderCreatePayload($product, $agent, $trip->id, $customer->id));

    $order = Order::first();
    expect(Payment::where('order_id', $order->id)->count())->toBe(0);
    expect((float) $order->deposit_paid)->toBe(0.0);
    expect(ActivityLog::where('action', 'payment.recorded')->where('subject_id', $order->id)->count())->toBe(0);
});

test('a payment_amount of 0 is treated the same as no payment at all', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $agent    = CsAgent::factory()->create();
    $product  = Product::create([
        'trip_id' => $trip->id, 'product_code' => 'PAYCREATE03',
        'price' => 100000, 'weight_gram' => 200, 'status' => 'active',
    ]);

    $this->actingAs($admin)->post(route('orders.store'), orderCreatePayload($product, $agent, $trip->id, $customer->id, [
        'payment_amount' => 0,
    ]));

    $order = Order::first();
    expect(Payment::where('order_id', $order->id)->count())->toBe(0);
});

test('paying enough to cover the total at creation marks the order fully paid immediately', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $agent    = CsAgent::factory()->create();
    $product  = Product::create([
        'trip_id' => $trip->id, 'product_code' => 'PAYCREATE04',
        'price' => 100000, 'weight_gram' => 200, 'status' => 'active',
    ]);

    // Deliberately overpay by a wide margin so this holds regardless of
    // whatever shipping/promo calculation lands on the exact total.
    $this->actingAs($admin)->post(route('orders.store'), orderCreatePayload($product, $agent, $trip->id, $customer->id, [
        'payment_amount' => 999999999,
        'payment_type'   => 'full',
    ]));

    $order = Order::first()->fresh();
    expect($order->payment_status)->toBe('paid');
});

test('a small payment at creation leaves the order partially paid, not fully paid', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $agent    = CsAgent::factory()->create();
    $product  = Product::create([
        'trip_id' => $trip->id, 'product_code' => 'PAYCREATE05',
        'price' => 100000, 'weight_gram' => 200, 'status' => 'active',
    ]);

    $this->actingAs($admin)->post(route('orders.store'), orderCreatePayload($product, $agent, $trip->id, $customer->id, [
        'payment_amount' => 1000, // well under any plausible total
        'payment_type'   => 'partial',
    ]));

    $order = Order::first()->fresh();
    expect($order->payment_status)->toBe('partial');
});
