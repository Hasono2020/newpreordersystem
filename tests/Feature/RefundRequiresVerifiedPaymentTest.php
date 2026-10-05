<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesAdjustment;

/*
 * The hole: a customer tells CS "I've transferred Rp 20.000.000" and hasn't.
 * Staff record the payment (it sits as Unverified), the system treats the
 * order as overpaid, and a Credit Note refunds real money that never arrived.
 *
 * Refunds are the one place money physically leaves the business, so they may
 * only draw on payments someone has VERIFIED. Recording a payment is not
 * proof it arrived.
 */

function orderWithPayment(object $test, $admin, int $total, int $paid, string $verification): Order
{
    $trip     = $test->openTrip();
    $customer = $test->customer($admin);
    $order = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'total_amount' => $total, 'order_number' => null]);
    Payment::factory()->create([
        'order_id' => $order->id, 'amount' => $paid, 'type' => 'deposit', 'paid_at' => now(),
        'voided_at' => null, 'verification_status' => $verification,
    ]);
    $order->recalcPaymentStatus();
    return $order->fresh();
}

test('a Credit Note cannot refund money from a payment that has not been verified', function () {
    $admin = $this->adminUser();
    $order = orderWithPayment($this, $admin, 100000, 20000000, 'unverified'); // "I transferred 20 million"

    $response = $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $order), [
        'type' => 'credit_note', 'amount' => 19900000, 'reason' => 'refund overpayment',
    ]);

    $response->assertSessionHas('error');
    expect(session('error'))->toContain('unverified');
    expect(SalesAdjustment::count())->toBe(0);
    expect(Payment::where('order_id', $order->id)->where('type', 'refund')->count())->toBe(0);
});

test('the same Credit Note goes through once the payment has been verified', function () {
    $admin = $this->adminUser();
    $order = orderWithPayment($this, $admin, 100000, 20000000, 'unverified');
    $payment = Payment::where('order_id', $order->id)->first();

    $this->actingAs($admin)->post(route('payments.verify', $payment)); // confirmed against the bank

    $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $order), [
        'type' => 'credit_note', 'amount' => 19900000,
    ])->assertSessionDoesntHaveErrors();

    expect(SalesAdjustment::count())->toBe(1);
    expect((float) $order->fresh()->deposit_paid)->toBe(100000.0);
});

test('a disputed payment cannot be refunded either', function () {
    $admin = $this->adminUser();
    $order = orderWithPayment($this, $admin, 100000, 500000, 'disputed');

    $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $order), [
        'type' => 'credit_note', 'amount' => 400000,
    ])->assertSessionHas('error');

    expect(SalesAdjustment::count())->toBe(0);
});

test('with part verified and part not, only the verified part can be refunded', function () {
    $admin = $this->adminUser();
    $order = orderWithPayment($this, $admin, 100000, 130000, 'verified');
    Payment::factory()->create([
        'order_id' => $order->id, 'amount' => 40000, 'type' => 'partial', 'paid_at' => now(),
        'voided_at' => null, 'verification_status' => 'unverified',
    ]);
    $order->recalcPaymentStatus(); // recorded 170,000, verified 130,000

    $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $order), [
        'type' => 'credit_note', 'amount' => 140000,
    ])->assertSessionHas('error');
    expect(SalesAdjustment::count())->toBe(0);

    $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $order), [
        'type' => 'credit_note', 'amount' => 130000,
    ])->assertSessionDoesntHaveErrors();
    expect(SalesAdjustment::count())->toBe(1);
});

test('a Sales Return on an order paid with unverified money refunds nothing automatically', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $product  = Product::create(['trip_id' => $trip->id, 'product_code' => 'VER01', 'price' => 100000, 'weight_gram' => 100, 'status' => 'active']);
    $order = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'order_number' => null]);
    $item  = OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 3, 'unit_price' => 100000, 'line_total' => 300000, 'status' => 'pending']);
    Payment::factory()->create(['order_id' => $order->id, 'amount' => 300000, 'type' => 'deposit', 'paid_at' => now(), 'voided_at' => null, 'verification_status' => 'unverified']);
    $order->recalcPaymentStatus();

    $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $order), [
        'type' => 'return', 'items' => [['order_item_id' => $item->id, 'quantity' => 1]],
    ]);

    expect($item->fresh()->quantity)->toBe(2);                                                 // the goods came back
    expect(Payment::where('order_id', $order->id)->where('type', 'refund')->count())->toBe(0); // but no money went out
});

test('a Sales Return cannot be forced to refund more than the verified money', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $product  = Product::create(['trip_id' => $trip->id, 'product_code' => 'VER02', 'price' => 100000, 'weight_gram' => 100, 'status' => 'active']);
    $order = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'order_number' => null]);
    $item  = OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 3, 'unit_price' => 100000, 'line_total' => 300000, 'status' => 'pending']);
    Payment::factory()->create(['order_id' => $order->id, 'amount' => 300000, 'type' => 'deposit', 'paid_at' => now(), 'voided_at' => null, 'verification_status' => 'unverified']);
    $order->recalcPaymentStatus();

    $response = $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $order), [
        'type' => 'return', 'refund_amount' => 100000,
        'items' => [['order_item_id' => $item->id, 'quantity' => 1]],
    ]);

    $response->assertSessionHas('error');
    expect($item->fresh()->quantity)->toBe(3);   // the whole return rolled back
    expect(SalesAdjustment::count())->toBe(0);
});

test('verified money moved between orders cannot be used to launder an unverified deposit into a refund', function () {
    // The subtler route: put the unverified deposit on one order, let credit
    // move onto a second order (those transfer entries are marked verified),
    // then refund from the second. Transfers only ever draw on VERIFIED money,
    // so nothing moves, and there is nothing verified to refund.
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $a = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'total_amount' => 30000, 'order_number' => null, 'created_at' => now()->subHour()]);
    $b = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'total_amount' => 150000, 'order_number' => null]);

    $this->actingAs($admin)->post(route('orders.payments.add', $a), [
        'amount' => 20000000, 'type' => 'deposit', 'paid_at' => now()->format('Y-m-d'), // unverified
    ]);

    expect(Payment::where('method', 'reallocation')->count())->toBe(0);
    expect((float) $b->fresh()->deposit_paid)->toBe(0.0);

    $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $b), [
        'type' => 'credit_note', 'amount' => 100000,
    ])->assertSessionHas('error');
    expect(SalesAdjustment::count())->toBe(0);
});
