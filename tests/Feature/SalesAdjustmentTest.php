<?php

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesAdjustment;

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

test('a credit note reduces the order total with no items involved', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $product  = Product::create(['trip_id' => $trip->id, 'product_code' => 'SA04', 'price' => 200000, 'weight_gram' => 100, 'status' => 'active']);
    $order = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'order_number' => null]);
    OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 200000, 'line_total' => 200000, 'status' => 'pending']);

    $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $order), [
        'type' => 'credit_note', 'amount' => 30000, 'reason' => 'Late delivery compensation',
    ]);

    expect((float) $order->fresh()->total_amount)->toBe(170000.0); // 200,000 - 30,000
    $payment = Payment::where('order_id', $order->id)->where('type', 'refund')->first();
    expect((float) $payment->amount)->toBe(30000.0);
});

test('a credit note deduction survives a LATER, unrelated recalculation of the order', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $agent    = \App\Models\CsAgent::factory()->create();
    $product  = Product::create(['trip_id' => $trip->id, 'product_code' => 'SA05', 'price' => 100000, 'weight_gram' => 100, 'status' => 'active']);
    $order = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id, 'order_number' => null]);
    OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100000, 'line_total' => 100000, 'status' => 'pending']);

    $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $order), [
        'type' => 'credit_note', 'amount' => 20000,
    ]);
    expect((float) $order->fresh()->total_amount)->toBe(80000.0);

    // A completely unrelated action that triggers a fresh recalculate() —
    // adding another item to the order. This is exactly the scenario that
    // would have silently erased a naive one-time subtraction.
    $product2 = Product::create(['trip_id' => $trip->id, 'product_code' => 'SA06', 'price' => 50000, 'weight_gram' => 50, 'status' => 'active']);
    $this->actingAs($admin)->post(route('orders.items.add', $order), [
        'product_id' => $product2->id, 'product_variant_id' => null, 'quantity' => 1, 'unit_price' => 50000,
    ]);

    // 100,000 (item 1) + 50,000 (item 2) - 20,000 (credit note, still applied) = 130,000
    expect((float) $order->fresh()->total_amount)->toBe(130000.0);
});

test('voiding a sales return restores the item quantity and the order total, and voids the refund payment', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $product  = Product::create(['trip_id' => $trip->id, 'product_code' => 'SA07', 'price' => 100000, 'weight_gram' => 100, 'status' => 'active']);
    $order = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'order_number' => null]);
    $item  = OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 2, 'unit_price' => 100000, 'line_total' => 200000, 'status' => 'pending']);

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

    $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $order), [
        'type' => 'credit_note', 'amount' => 15000,
    ]);
    expect(ActivityLog::where('action', 'credit_note.issued')->count())->toBe(1);

    $adjustment = SalesAdjustment::first();
    $this->actingAs($admin)->post(route('sales-adjustments.void', $adjustment));
    expect(ActivityLog::where('action', 'sales_adjustment.voided')->count())->toBe(1);
});
