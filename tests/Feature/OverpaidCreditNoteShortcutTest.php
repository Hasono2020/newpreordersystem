<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;

/*
 * Design: Credit Note stays anchored to the order page, but the Overpaid
 * row on Payment Log auto-picks a sensible default order — the one
 * already carrying this customer's combined shipping fee/discount for
 * this trip, the same "anchor" concept recalcCustomerShipping() already
 * uses — instead of making staff guess from a bare list of order numbers.
 * An override is still available, but shows each order's own total/
 * balance so it's an informed choice, not a second guess.
 */

test('a customer with exactly one order gets a direct Credit Note link to it, pre-filled', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $order = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id,
        'total_amount' => 100000, 'deposit_paid' => 170000, // overpaid by 70,000
        'order_number' => null,
    ]);

    $response = $this->actingAs($admin)->get(route('payments.index', ['trip_id' => $trip->id]));

    $response->assertOk();
    $response->assertSee(
        route('orders.show', $order->id) . '?open_credit_note=1&credit_amount=70000',
        false
    );
    // Only one order — no override dropdown needed.
    $response->assertDontSee('Use a different order');
});

test('with multiple orders, the default Credit Note link points at the one carrying combined shipping — not just the first one found', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $product  = Product::create(['trip_id' => $trip->id, 'product_code' => 'ANCHOR01', 'price' => 50000, 'weight_gram' => 100, 'status' => 'active']);

    // Order A is chronologically first, but every item on it has been
    // returned (status stays 'pending', quantity drops to 0) — it should
    // NOT be the default, even though it was created first.
    $orderA = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id,
        'total_amount' => 0, 'deposit_paid' => 0, 'order_number' => null,
        'created_at' => now()->subHour(),
    ]);
    OrderItem::create(['order_id' => $orderA->id, 'product_id' => $product->id, 'quantity' => 0, 'unit_price' => 50000, 'line_total' => 0, 'status' => 'pending']);

    // Order B is created after A, still has its item — this is the real anchor.
    $orderB = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id,
        'total_amount' => 50000, 'deposit_paid' => 120000, // overpaid by 70,000
        'order_number' => null,
    ]);
    OrderItem::create(['order_id' => $orderB->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50000, 'line_total' => 50000, 'status' => 'pending']);

    $response = $this->actingAs($admin)->get(route('payments.index', ['trip_id' => $trip->id]));

    $response->assertOk();
    $response->assertSee(
        route('orders.show', $orderB->id) . '?open_credit_note=1&credit_amount=70000',
        false
    );
});

test('the override list shows each order\'s own total and balance, not just a bare order number', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $orderA = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id,
        'total_amount' => 50000, 'deposit_paid' => 50000, 'order_number' => null,
    ]);
    $orderB = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id,
        'total_amount' => 50000, 'deposit_paid' => 100000, 'order_number' => null,
    ]);

    $response = $this->actingAs($admin)->get(route('payments.index', ['trip_id' => $trip->id]));

    $response->assertOk();
    $response->assertSee('Use a different order');
    $response->assertSee($orderA->order_number);
    $response->assertSee($orderB->order_number);
    $response->assertSee('settled'); // orderA: paid == total
    $response->assertSee('overpaid'); // orderB: paid > total
});

test('a customer with no overpayment does not appear on the Overpaid panel at all', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id,
        'total_amount' => 100000, 'deposit_paid' => 100000, 'order_number' => null,
    ]);

    $response = $this->actingAs($admin)->get(route('payments.index', ['trip_id' => $trip->id]));
    $response->assertDontSee('Overpaid — these customers');
});

test('visiting an order page via the shortcut auto-opens the Credit Note panel with the amount pre-filled', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $order = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'order_number' => null]);

    $response = $this->actingAs($admin)->get(route('orders.show', $order->id) . '?open_credit_note=1&credit_amount=70000');

    $response->assertOk();
    $response->assertSee('creditNotePanel');
    $response->assertSee('amountField.value = 70000', false);
});

test('visiting an order page normally does not auto-open the Credit Note panel', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $order = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'order_number' => null]);

    $response = $this->actingAs($admin)->get(route('orders.show', $order->id));

    $response->assertOk();
    $response->assertDontSee('amountField.value', false);
});
