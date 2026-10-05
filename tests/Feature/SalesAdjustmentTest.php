<?php

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesAdjustment;

function payOrder(Order $order, int $userId, float $amount): void
{
    Payment::create(['order_id' => $order->id, 'amount' => $amount, 'type' => 'deposit', 'method' => 'Transfer', 'paid_at' => now(), 'recorded_by' => $userId, 'verification_status' => 'verified']);
    $order->recalcPaymentStatus();
}

/*
 * Sales Return: goods come back, tied to specific order items — reduces
 * the order's items directly (same "shrink in place" pattern as the PO
 * arrival correction), which the existing recalculate() picks up via its
 * normal item sum. Credit Note: money back only, no items — recalculate()
 * was taught a new deduction step for this, specifically so it survives
 * any FUTURE recalculation of the order, not just a one-time subtraction.
 * Both create a linked, auto-verified refund Payment, reusing the
 * already-correct recalcPaymentStatus() math rather than a parallel one.
 */

test('sales return and credit note numbers use their own sequence, independent of order numbers', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $trip->update(['batch_number' => 70]);
    $customer = $this->customer($admin);
    $product  = Product::create(['trip_id' => $trip->id, 'product_code' => 'SA01', 'price' => 100000, 'weight_gram' => 100, 'status' => 'active']);
    $order = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'total_amount' => 200000, 'order_number' => null]);
    $item  = OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 2, 'unit_price' => 100000, 'line_total' => 200000, 'status' => 'pending']);

    payOrder($order, $admin->id, 100000);

    $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $order), [
        'type' => 'credit_note', 'amount' => 50000,
    ]);

    $month = now()->format('m');
    $adj = SalesAdjustment::first();
    expect($adj->adjustment_number)->toBe("CR/B70/{$month}/000001");

    // Order number sequence is untouched by this — still whatever it was.
    expect($order->fresh()->order_number)->not->toContain('CR/');
});

test('a sales return reduces the item quantity, the order total, and creates a matching refund payment', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $trip->update(['batch_number' => 71]);
    $customer = $this->customer($admin);
    $product  = Product::create(['trip_id' => $trip->id, 'product_code' => 'SA02', 'price' => 100000, 'weight_gram' => 100, 'status' => 'active']);
    $order = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'total_amount' => 300000, 'order_number' => null]);
    $item  = OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 3, 'unit_price' => 100000, 'line_total' => 300000, 'status' => 'pending']);

    payOrder($order, $admin->id, 300000); // fully paid, so returning 1 unit leaves 100,000 to give back

    $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $order), [
        'type' => 'return', 'reason' => 'Wrong size',
        'items' => [['order_item_id' => $item->id, 'quantity' => 1]],
    ]);

    expect($item->fresh()->quantity)->toBe(2);
    expect((float) $order->fresh()->total_amount)->toBe(200000.0);

    $adjustment = SalesAdjustment::first();
    expect($adjustment->type)->toBe('return');
    expect((float) $adjustment->amount)->toBe(100000.0);

    $payment = Payment::where('sales_adjustment_id', $adjustment->id)->first();
    expect($payment)->not->toBeNull();
    expect((float) $payment->amount)->toBe(100000.0);
    expect($payment->type)->toBe('refund');
    expect($payment->verification_status)->toBe('verified'); // must not block Ready to Pack
});

test('a sales return cannot exceed the quantity actually on the order', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $product  = Product::create(['trip_id' => $trip->id, 'product_code' => 'SA03', 'price' => 50000, 'weight_gram' => 100, 'status' => 'active']);
    $order = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'order_number' => null]);
    $item  = OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 2, 'unit_price' => 50000, 'line_total' => 100000, 'status' => 'pending']);

    $response = $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $order), [
        'type' => 'return',
        'items' => [['order_item_id' => $item->id, 'quantity' => 5]],
    ]);

    $response->assertStatus(422);
    expect($item->fresh()->quantity)->toBe(2); // unchanged
    expect(SalesAdjustment::count())->toBe(0);
});

test('a credit note refunds money — paid goes down, the order total does NOT change', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $order = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'total_amount' => 200000, 'order_number' => null]);
    payOrder($order, $admin->id, 200000);

    $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $order), [
        'type' => 'credit_note', 'amount' => 30000, 'reason' => 'Goodwill refund',
    ]);

    $order->refresh();
    expect((float) $order->total_amount)->toBe(200000.0); // still owes the full price — keeps every item
    expect((float) $order->deposit_paid)->toBe(170000.0); // 200,000 paid - 30,000 refunded
    $payment = Payment::where('order_id', $order->id)->where('type', 'refund')->first();
    expect((float) $payment->amount)->toBe(30000.0);
});

test('a credit note actually CLEARS an overpayment instead of leaving it exactly as large as before', function () {
    // The reported bug: customer paid 170,000 on a 100,000 order (70,000
    // over). Refunding that 70,000 should leave paid == total. The old
    // version also lowered the total by 70,000, so paid and total fell
    // together and the overpayment stayed at 70,000.
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $order = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'total_amount' => 100000, 'order_number' => null]);
    payOrder($order, $admin->id, 170000);

    $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $order), [
        'type' => 'credit_note', 'amount' => 70000,
    ]);

    $order->refresh();
    expect((float) $order->total_amount)->toBe(100000.0);
    expect((float) $order->deposit_paid)->toBe(100000.0);
    expect($order->payment_status)->toBe('paid'); // balanced — no longer overpaid
});

test('a credit note larger than what the order has paid is refused and records nothing', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $order = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'total_amount' => 100000, 'order_number' => null]);
    payOrder($order, $admin->id, 50000);

    $response = $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $order), [
        'type' => 'credit_note', 'amount' => 80000,
    ]);

    $response->assertSessionHas('error');
    expect(SalesAdjustment::count())->toBe(0);
    expect(Payment::where('order_id', $order->id)->where('type', 'refund')->count())->toBe(0);
    expect((float) $order->fresh()->deposit_paid)->toBe(50000.0);
});

test('with two orders, the credit note must be issued on the one holding the payment, and spare credit then covers the other order', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $orderA = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'total_amount' => 100000, 'order_number' => null]);
    $orderB = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'total_amount' => 100000, 'order_number' => null]);
    payOrder($orderB, $admin->id, 300000); // all the money sits on B; A has nothing paid

    // A cannot refund money it never received.
    $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $orderA), [
        'type' => 'credit_note', 'amount' => 100000,
    ])->assertSessionHas('error');
    expect(SalesAdjustment::count())->toBe(0);

    // B holds it, so B can. Of B's remaining 200,000, only 100,000 is B's own
    // price — the other 100,000 is spare credit that now covers A automatically.
    $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $orderB), [
        'type' => 'credit_note', 'amount' => 100000,
    ]);
    expect((float) $orderB->fresh()->deposit_paid)->toBe(100000.0);
    expect((float) $orderA->fresh()->deposit_paid)->toBe(100000.0);
    expect($orderA->fresh()->payment_status)->toBe('paid');
});

test('a credit note does not change any order total, even after a later unrelated recalculation', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $customer->update(['default_shipping_area_id' => null]);
    $product  = Product::create(['trip_id' => $trip->id, 'product_code' => 'SA05', 'price' => 100000, 'weight_gram' => 100, 'status' => 'active']);
    $order = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'shipping_area_id' => null, 'created_by' => $admin->id, 'order_number' => null]);
    OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100000, 'line_total' => 100000, 'status' => 'pending']);
    $this->actingAs($admin)->post(route('orders.items.add', $order), [
        'product_id' => $product->id, 'product_variant_id' => null, 'quantity' => 1, 'unit_price' => 100000,
    ]); // forces a real recalculation: 2 x 100,000
    payOrder($order->fresh(), $admin->id, 200000);

    $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $order), [
        'type' => 'credit_note', 'amount' => 20000,
    ]);

    $product2 = Product::create(['trip_id' => $trip->id, 'product_code' => 'SA06', 'price' => 50000, 'weight_gram' => 50, 'status' => 'active']);
    $this->actingAs($admin)->post(route('orders.items.add', $order), [
        'product_id' => $product2->id, 'product_variant_id' => null, 'quantity' => 1, 'unit_price' => 50000,
    ]);

    // Items alone decide the total: 200,000 + 50,000. The refund lives in paid.
    expect((float) $order->fresh()->total_amount)->toBe(250000.0);
    expect((float) $order->fresh()->deposit_paid)->toBe(180000.0);
});

test('voiding a sales return restores the item quantity and the order total, and voids the refund payment', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $product  = Product::create(['trip_id' => $trip->id, 'product_code' => 'SA07', 'price' => 100000, 'weight_gram' => 100, 'status' => 'active']);
    $order = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'order_number' => null]);
    $item  = OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 2, 'unit_price' => 100000, 'line_total' => 200000, 'status' => 'pending']);

    payOrder($order, $admin->id, 200000);

    $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $order), [
        'type' => 'return', 'items' => [['order_item_id' => $item->id, 'quantity' => 1]],
    ]);
    $adjustment = SalesAdjustment::first();
    expect($item->fresh()->quantity)->toBe(1);

    $this->actingAs($admin)->post(route('sales-adjustments.void', $adjustment), ['void_reason' => 'Mistake']);

    expect($item->fresh()->quantity)->toBe(2); // restored
    expect((float) $order->fresh()->total_amount)->toBe(200000.0); // restored
    expect($adjustment->fresh()->isVoided())->toBeTrue();
    expect(Payment::where('sales_adjustment_id', $adjustment->id)->first()->isVoided())->toBeTrue();
});

test('an order with every item fully returned is flagged as fully returned', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $product  = Product::create(['trip_id' => $trip->id, 'product_code' => 'SA08', 'price' => 100000, 'weight_gram' => 100, 'status' => 'active']);
    $order = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'order_number' => null]);
    $item  = OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 2, 'unit_price' => 100000, 'line_total' => 200000, 'status' => 'pending']);

    $order->load('items');
    expect($order->isFullyReturned())->toBeFalse();

    $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $order), [
        'type' => 'return', 'items' => [['order_item_id' => $item->id, 'quantity' => 2]],
    ]);

    $order->load('items');
    expect($order->isFullyReturned())->toBeTrue();
});

test('a partial return does not flag the order as fully returned', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $product  = Product::create(['trip_id' => $trip->id, 'product_code' => 'SA09', 'price' => 100000, 'weight_gram' => 100, 'status' => 'active']);
    $order = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'order_number' => null]);
    $item  = OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 3, 'unit_price' => 100000, 'line_total' => 300000, 'status' => 'pending']);

    $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $order), [
        'type' => 'return', 'items' => [['order_item_id' => $item->id, 'quantity' => 1]],
    ]);

    $order->load('items');
    expect($order->isFullyReturned())->toBeFalse();
});

test('a staff member without orders.sales_adjustments permission cannot issue a return or credit note', function () {
    $staff = $this->staffUser(); // role default: orders.sales_adjustments = false
    $trip  = $this->openTrip();
    $customer = $this->customer($staff);
    $product  = Product::create(['trip_id' => $trip->id, 'product_code' => 'SA10', 'price' => 50000, 'weight_gram' => 100, 'status' => 'active']);
    $order = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'order_number' => null]);

    $response = $this->actingAs($staff)->post(route('orders.sales-adjustments.store', $order), [
        'type' => 'credit_note', 'amount' => 10000,
    ]);

    $response->assertForbidden();
    expect(SalesAdjustment::count())->toBe(0);
});

test('issuing and voiding both log distinct, specific Activity Log entries', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $product  = Product::create(['trip_id' => $trip->id, 'product_code' => 'SA11', 'price' => 40000, 'weight_gram' => 100, 'status' => 'active']);
    $order = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'order_number' => null]);

    payOrder($order, $admin->id, 40000);

    $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $order), [
        'type' => 'credit_note', 'amount' => 15000,
    ]);
    expect(ActivityLog::where('action', 'credit_note.issued')->count())->toBe(1);

    $adjustment = SalesAdjustment::first();
    $this->actingAs($admin)->post(route('sales-adjustments.void', $adjustment));
    expect(ActivityLog::where('action', 'sales_adjustment.voided')->count())->toBe(1);
});

test('a sales return on an order holding no payment records no refund at all', function () {
    // The phantom-refund bug: a return always "refunded" the full returned
    // value even when nothing had been paid on that order, which the paid
    // figure (never below zero) silently swallowed.
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $product  = Product::create(['trip_id' => $trip->id, 'product_code' => 'PH01', 'price' => 100000, 'weight_gram' => 100, 'status' => 'active']);
    $order = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'order_number' => null]);
    $item  = OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 3, 'unit_price' => 100000, 'line_total' => 300000, 'status' => 'pending']);

    $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $order), [
        'type' => 'return', 'items' => [['order_item_id' => $item->id, 'quantity' => 1]],
    ]);

    expect($item->fresh()->quantity)->toBe(2);                                   // the return itself still happens
    expect(SalesAdjustment::count())->toBe(1);
    expect(Payment::where('order_id', $order->id)->where('type', 'refund')->count())->toBe(0); // no phantom money-out
});

test('a sales return refunds only the part of what was paid that now exceeds the new total', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $product  = Product::create(['trip_id' => $trip->id, 'product_code' => 'PH02', 'price' => 100000, 'weight_gram' => 100, 'status' => 'active']);
    $order = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'order_number' => null]);
    $item  = OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 3, 'unit_price' => 100000, 'line_total' => 300000, 'status' => 'pending']);
    payOrder($order, $admin->id, 250000); // owed 300,000, paid 250,000

    // Return 1 unit (100,000): new total 200,000, so only 50,000 of the 250,000 paid is now surplus.
    $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $order), [
        'type' => 'return', 'items' => [['order_item_id' => $item->id, 'quantity' => 1]],
    ]);

    $refund = Payment::where('order_id', $order->id)->where('type', 'refund')->first();
    expect((float) $refund->amount)->toBe(50000.0);
    expect((float) $order->fresh()->deposit_paid)->toBe(200000.0);
    expect($order->fresh()->payment_status)->toBe('paid');
});

test('an explicit refund larger than what the order has paid is refused and the whole return rolls back', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $product  = Product::create(['trip_id' => $trip->id, 'product_code' => 'PH03', 'price' => 100000, 'weight_gram' => 100, 'status' => 'active']);
    $order = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'order_number' => null]);
    $item  = OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 3, 'unit_price' => 100000, 'line_total' => 300000, 'status' => 'pending']);
    payOrder($order, $admin->id, 100000);

    $response = $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $order), [
        'type' => 'return', 'refund_amount' => 150000,
        'items' => [['order_item_id' => $item->id, 'quantity' => 1]],
    ]);

    $response->assertSessionHas('error');
    expect($item->fresh()->quantity)->toBe(3);      // nothing was shrunk
    expect(SalesAdjustment::count())->toBe(0);       // nothing was recorded
});

test('after a sales return, spare credit on another order covers the order that is short', function () {
    // The reported case: the money sits on the first order, a later order is
    // unpaid, and the combined position is settled — but the later order kept
    // saying "Unpaid" because nothing moved the credit across.
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $customer->update(['default_shipping_area_id' => null]);
    $p1 = Product::create(['trip_id' => $trip->id, 'product_code' => 'RB01', 'price' => 100000, 'weight_gram' => 100, 'status' => 'active']);
    $p2 = Product::create(['trip_id' => $trip->id, 'product_code' => 'RB02', 'price' => 80000,  'weight_gram' => 100, 'status' => 'active']);
    $p3 = Product::create(['trip_id' => $trip->id, 'product_code' => 'RB03', 'price' => 20000,  'weight_gram' => 100, 'status' => 'active']);

    $orderA = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'shipping_area_id' => null, 'order_number' => null, 'created_at' => now()->subHour()]);
    OrderItem::create(['order_id' => $orderA->id, 'product_id' => $p1->id, 'quantity' => 1, 'unit_price' => 100000, 'line_total' => 100000, 'status' => 'pending']);
    $orderB = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'shipping_area_id' => null, 'order_number' => null]);
    OrderItem::create(['order_id' => $orderB->id, 'product_id' => $p2->id, 'quantity' => 1, 'unit_price' => 80000, 'line_total' => 80000, 'status' => 'pending']);
    $returnLine = OrderItem::create(['order_id' => $orderB->id, 'product_id' => $p3->id, 'quantity' => 1, 'unit_price' => 20000, 'line_total' => 20000, 'status' => 'pending']);

    payOrder($orderA, $admin->id, 190000); // all the money is on A

    $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $orderB), [
        'type' => 'return', 'items' => [['order_item_id' => $returnLine->id, 'quantity' => 1]],
    ]);

    // A owes 100,000 and holds 190,000; B now owes 80,000 and holds nothing.
    // The 80,000 moves across; 10,000 stays as A's genuine overpayment.
    expect((float) $orderB->fresh()->total_amount)->toBe(80000.0);
    expect((float) $orderB->fresh()->deposit_paid)->toBe(80000.0);
    expect($orderB->fresh()->payment_status)->toBe('paid');
    expect((float) $orderA->fresh()->deposit_paid)->toBe(110000.0);
    expect(ActivityLog::where('action', 'payment.auto_reallocated')->count())->toBe(1);
    // And no phantom refund was recorded on B along the way.
    expect(Payment::where('order_id', $orderB->id)->where('type', 'refund')->whereNotNull('sales_adjustment_id')->count())->toBe(0);
});

test('after a credit note, the remaining spare credit covers a short order', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $orderA = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'total_amount' => 100000, 'order_number' => null, 'created_at' => now()->subHour()]);
    $orderB = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'total_amount' => 60000, 'order_number' => null]);
    payOrder($orderA, $admin->id, 150000); // 50,000 over on A; B unpaid

    $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $orderA), [
        'type' => 'credit_note', 'amount' => 10000,
    ]);

    // A: 150,000 - 10,000 refunded = 140,000, so 40,000 spare. B was short 60,000.
    expect((float) $orderA->fresh()->deposit_paid)->toBe(100000.0);
    expect((float) $orderB->fresh()->deposit_paid)->toBe(40000.0);
    expect($orderB->fresh()->payment_status)->toBe('partial');
});
