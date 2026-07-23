<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ShippingArea;
use App\Models\Trip;

/*
 * Reallocation payments (the paired refund + partial records
 * CreditReallocationService creates when moving credit between two of a
 * customer's own orders) are an internal accounting correction, not a
 * real transaction. Showing "Refund +Rp160,000" followed by "Partial
 * +Rp160,000" on the same invoice date made it look like money
 * appeared and disappeared for no reason - confusing on a document
 * meant to show the customer what they actually paid.
 *
 * These payments should still be fully visible on the staff-facing
 * order page (for audit purposes) - only the customer-facing invoices
 * hide them.
 */

function reallocInvoiceOrder(Trip $trip, ShippingArea $area, Customer $customer, float $total, float $realPaid): Order
{
    $product = Product::create([
        'trip_id' => $trip->id, 'product_code' => 'INV_'.fake()->unique()->numerify('####'),
        'price' => $total, 'weight_gram' => 0, 'status' => 'active',
    ]);
    $order = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id,
        'shipping_area_id' => $area->id, 'subtotal' => $total, 'total_amount' => $total,
        'deposit_paid' => $realPaid, 'payment_status' => $realPaid >= $total ? 'paid' : 'partial',
    ]);
    OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => $total, 'line_total' => $total, 'status' => 'pending']);
    if ($realPaid > 0) {
        Payment::factory()->create(['order_id' => $order->id, 'amount' => $realPaid, 'type' => 'deposit', 'paid_at' => now(), 'voided_at' => null]);
    }
    return $order;
}

test('a reallocation payment does not appear on the single-order invoice', function () {
    $admin    = $this->adminUser();
    $area     = ShippingArea::factory()->create();
    $trip     = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $customer = $this->customer($admin);

    $order = reallocInvoiceOrder($trip, $area, $customer, 340000, 340000);
    Payment::factory()->create([
        'order_id' => $order->id, 'amount' => 160000, 'type' => 'refund', 'method' => 'reallocation',
        'reference' => 'Reallocated to ORD-TEST', 'paid_at' => now(), 'voided_at' => null,
        'verification_status' => 'verified',
    ]);

    $response = $this->actingAs($admin)->get(route('orders.invoice', $order));
    $response->assertOk()
        ->assertDontSee('reallocation')
        ->assertDontSee('Reallocated to');
});

test('a reallocation payment does not appear on the combined invoice', function () {
    $admin    = $this->adminUser();
    $area     = ShippingArea::factory()->create();
    $trip     = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $customer = $this->customer($admin);

    $order = reallocInvoiceOrder($trip, $area, $customer, 340000, 340000);
    Payment::factory()->create([
        'order_id' => $order->id, 'amount' => 160000, 'type' => 'refund', 'method' => 'reallocation',
        'reference' => 'Reallocated to ORD-TEST', 'paid_at' => now(), 'voided_at' => null,
        'verification_status' => 'verified',
    ]);

    $response = $this->actingAs($admin)->get(route('orders.combined-invoice', ['customer' => $customer->id, 'trip_id' => $trip->id]));
    $response->assertOk()
        ->assertDontSee('reallocation')
        ->assertDontSee('Reallocated to');
});

test('the combined invoice Total Paid figure is unaffected by hiding reallocation entries from the list', function () {
    $admin    = $this->adminUser();
    $area     = ShippingArea::factory()->create();
    $trip     = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $customer = $this->customer($admin);

    // deposit_paid already reflects the reallocation (340,000 total paid,
    // matching a real 340,000 deposit) - the hidden reallocation payment
    // records exist alongside it but must not double-count or under-count.
    $order = reallocInvoiceOrder($trip, $area, $customer, 340000, 340000);
    Payment::factory()->create([
        'order_id' => $order->id, 'amount' => 160000, 'type' => 'refund', 'method' => 'reallocation',
        'reference' => 'Reallocated to ORD-TEST', 'paid_at' => now(), 'voided_at' => null,
        'verification_status' => 'verified',
    ]);

    $response = $this->actingAs($admin)->get(route('orders.combined-invoice', ['customer' => $customer->id, 'trip_id' => $trip->id]));
    $response->assertOk()->assertSee('Rp 340.000');
});

test('a normal (non-reallocation) payment still shows on both invoices', function () {
    $admin    = $this->adminUser();
    $area     = ShippingArea::factory()->create();
    $trip     = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $customer = $this->customer($admin);

    $order = reallocInvoiceOrder($trip, $area, $customer, 340000, 340000);

    $response = $this->actingAs($admin)->get(route('orders.invoice', $order));
    $response->assertOk()->assertSee('Deposit');
});
