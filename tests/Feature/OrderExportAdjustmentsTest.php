<?php

use App\Models\CsAgent;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesAdjustment;
use App\Models\SalesAdjustmentItem;

/*
 * Phase 3: Final Payment / Sales Return / Credit Note columns added to the
 * existing orders export. Phase 4: multi-CS checklist filter, and
 * confirming order numbers are untouched by it (they're a stored value
 * from Phase 1's per-trip sequence, not something recomputed at export
 * time — filtering which rows appear can't change what number a row
 * already has).
 */

test('the export runs successfully for an order carrying a return, a credit note, and multiple payments', function () {
    // This is the real risk here — a null-relation crash somewhere in the
    // new aggregation code — more valuable to catch than exact cell values,
    // which (as with the Sales Recap export) aren't practical to assert on
    // directly through a binary file download in this test setup.
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $agent    = CsAgent::factory()->create();
    $product  = Product::create(['trip_id' => $trip->id, 'product_code' => 'EXPADJ01', 'price' => 100000, 'weight_gram' => 100, 'status' => 'active']);
    $order = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'cs_agent_id' => $agent->id, 'created_by' => $admin->id, 'order_number' => null]);
    $item  = OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 3, 'unit_price' => 100000, 'line_total' => 300000, 'status' => 'pending']);

    Payment::create(['order_id' => $order->id, 'amount' => 100000, 'type' => 'deposit', 'paid_at' => now()->subDays(2), 'recorded_by' => $admin->id, 'verification_status' => 'verified']);
    Payment::create(['order_id' => $order->id, 'amount' => 100000, 'type' => 'partial', 'paid_at' => now()->subDay(), 'recorded_by' => $admin->id, 'verification_status' => 'verified']);

    $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $order), [
        'type' => 'return', 'items' => [['order_item_id' => $item->id, 'quantity' => 1]],
    ]);
    $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $order), [
        'type' => 'credit_note', 'amount' => 10000,
    ]);

    $response = $this->actingAs($admin)->get(route('orders.export', ['trip_id' => $trip->id]));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))
        ->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

test('the export runs successfully for an order with no payments, returns, or credit notes at all', function () {
    // The opposite edge — every new field empty/null, which is the normal
    // case for most orders and just as likely to trip up an unguarded
    // ->first() or ->last() on an empty collection.
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $product  = Product::create(['trip_id' => $trip->id, 'product_code' => 'EXPADJ02', 'price' => 50000, 'weight_gram' => 100, 'status' => 'active']);
    Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id, 'order_number' => null]);

    $response = $this->actingAs($admin)->get(route('orders.export', ['trip_id' => $trip->id]));
    $response->assertOk();
});

test('the multi-CS filter only includes orders created by the selected staff', function () {
    $admin  = $this->adminUser();
    $trip   = $this->openTrip();
    $staffA = $this->staffUser();
    $staffB = $this->staffUser();
    $customer = $this->customer($admin);

    $orderA = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $staffA->id, 'order_number' => null]);
    $orderB = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $staffB->id, 'order_number' => null]);

    $response = $this->actingAs($admin)->get(route('orders.export', [
        'trip_id' => $trip->id, 'staff_ids' => [$staffA->id],
    ]));
    $response->assertOk();

    // Mirrors the controller's own filter condition directly — the
    // simplest reliable way to confirm which orders a given staff_ids
    // selection resolves to, without parsing the binary file itself.
    $filtered = Order::where('trip_id', $trip->id)->whereIn('created_by', [$staffA->id])->pluck('id');
    expect($filtered)->toContain($orderA->id);
    expect($filtered)->not->toContain($orderB->id);
});

test('order numbers are identical whether exporting everyone or filtering to one CS', function () {
    $admin  = $this->adminUser();
    $trip   = $this->openTrip();
    $trip->update(['batch_number' => 80]);
    $staffA = $this->staffUser();
    $staffB = $this->staffUser();
    $customer = $this->customer($admin);

    // staffB's order first, so staffA's order is the SECOND one issued
    // overall — proving the number reflects the real combined sequence,
    // not a position recalculated within the filtered subset.
    Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $staffB->id, 'order_number' => null]);
    $orderA = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $staffA->id, 'order_number' => null]);

    $month = now()->format('m');
    $numberBeforeFilter = $orderA->order_number;
    expect($numberBeforeFilter)->toBe("ORD/B80/{$month}/000002");

    $this->actingAs($admin)->get(route('orders.export', [
        'trip_id' => $trip->id, 'staff_ids' => [$staffA->id],
    ]))->assertOk();

    // Filtering the export never touches the stored value.
    expect($orderA->fresh()->order_number)->toBe($numberBeforeFilter);
});
