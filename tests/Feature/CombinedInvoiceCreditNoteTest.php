<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;

/*
 * Reported: after issuing a Credit Note to refund an overpayment, the
 * combined invoice's Grand Total DROPPED by the credit note amount while
 * Total Paid stayed put, so the overpayment doubled instead of clearing.
 *
 * Root cause: a Credit Note is a refund of money already paid (the customer
 * keeps the goods), so it must reduce PAID and leave what's OWED alone.
 * It had been lowering the order total as well, which moves paid and owed
 * together and leaves the gap between them exactly where it started.
 *
 * Now: Grand Total is untouched by a Credit Note; it appears only in Payment
 * History as a refund, reducing Total Paid.
 */

function makeCombinedOrder(object $test, int $tripId, int $customerId, int $adminId, string $code, int $price): Order
{
    $product = Product::create(['trip_id' => $tripId, 'product_code' => $code, 'price' => $price, 'weight_gram' => 100, 'status' => 'active']);
    $order = Order::factory()->create([
        'trip_id' => $tripId, 'customer_id' => $customerId, 'created_by' => $adminId,
        'shipping_area_id' => null, 'subtotal' => $price, 'discount_amount' => 0,
        'shipping_fee' => 0, 'shipping_discount' => 0, 'total_amount' => $price, 'order_number' => null,
    ]);
    OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => $price, 'line_total' => $price, 'status' => 'pending']);
    return $order;
}

test('refunding an overpayment with a credit note leaves the Grand Total alone and clears the balance', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $customer->update(['default_shipping_area_id' => null]); // keep shipping out of the arithmetic

    $order = makeCombinedOrder($this, $trip->id, $customer->id, $admin->id, 'CIBUG01', 500000);
    Payment::create(['order_id' => $order->id, 'amount' => 570000, 'type' => 'deposit', 'method' => 'Transfer', 'paid_at' => now(), 'recorded_by' => $admin->id, 'verification_status' => 'verified']);
    $order->recalcPaymentStatus(); // paid 570,000 vs 500,000 owed: overpaid by 70,000

    $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $order), [
        'type' => 'credit_note', 'amount' => 70000, 'reason' => 'refund overpayment',
    ]);

    $response = $this->actingAs($admin)->get(route('orders.combined-invoice', [
        'customer' => $customer->id, 'trip_id' => $trip->id,
    ]));

    $response->assertOk();
    $response->assertSeeText('Rp 500.000');       // Grand Total unchanged
    $response->assertDontSeeText('Rp 430.000');   // the old, wrong "total minus credit note"
    $response->assertSeeText('Balance Due');
    $response->assertSeeText('Rp 0');              // 500,000 owed - 500,000 paid
    $response->assertSeeText('Credit Note');       // shown in Payment History
});

test('with the money sitting on a different order, the credit note still reduces the combined Total Paid', function () {
    // The reported setup: the deposit was recorded on one order while the
    // credit note landed on another, so the refund had no effect on paid at
    // all (an order's paid never goes below zero).
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $customer->update(['default_shipping_area_id' => null]);

    $orderA = makeCombinedOrder($this, $trip->id, $customer->id, $admin->id, 'CIBUG02', 100000);
    $orderB = makeCombinedOrder($this, $trip->id, $customer->id, $admin->id, 'CIBUG03', 100000);

    Payment::create(['order_id' => $orderB->id, 'amount' => 270000, 'type' => 'deposit', 'method' => 'Transfer', 'paid_at' => now(), 'recorded_by' => $admin->id, 'verification_status' => 'verified']);
    $orderB->recalcPaymentStatus(); // 270,000 paid vs 200,000 owed across both: overpaid 70,000

    // The order with no payments of its own can't refund anything...
    $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $orderA), [
        'type' => 'credit_note', 'amount' => 70000,
    ])->assertSessionHas('error');

    // ...the one that actually holds the payment can.
    $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $orderB), [
        'type' => 'credit_note', 'amount' => 70000,
    ]);

    $response = $this->actingAs($admin)->get(route('orders.combined-invoice', [
        'customer' => $customer->id, 'trip_id' => $trip->id,
    ]));

    $response->assertOk();
    $response->assertSeeText('Rp 200.000');  // Grand Total and Total Paid now agree
    $response->assertSeeText('Balance Due');
    $response->assertSeeText('Rp 0');         // owed 200,000, paid 200,000: overpayment cleared
    // (The original +Rp 270.000 deposit still shows in Payment History, correctly —
    // it's the Total Paid and Balance Due lines that must reflect the refund.)
});
