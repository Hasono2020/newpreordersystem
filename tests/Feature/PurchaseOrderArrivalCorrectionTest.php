<?php

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Trip;
use App\Models\User;

/*
 * Reported: staff sometimes typed the wrong received quantity when
 * confirming a PO arrival, and the only way to fix it was deleting the
 * entire PO and re-entering every line from scratch — painful for POs with
 * 100+ lines, when only one number was actually wrong.
 *
 * Fix: re-confirming an already-arrived PO now reverses the previous FIFO
 * allocation first (reusing the same logic Delete already used), then
 * re-runs allocation with the corrected numbers — so staff just edit the
 * Received field and click "Correct Received Qty" instead of deleting
 * anything.
 */

function makePoScenario(User $admin, Trip $trip, Customer $customer): array
{
    $product = Product::create([
        'trip_id' => $trip->id, 'product_code' => 'POFIX01',
        'price' => 50000, 'weight_gram' => 100, 'status' => 'active',
    ]);
    $order = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
    ]);
    $orderItem = OrderItem::create([
        'order_id' => $order->id, 'product_id' => $product->id,
        'quantity' => 10, 'unit_price' => 50000, 'line_total' => 500000, 'status' => 'pending',
    ]);
    $po = PurchaseOrder::create([
        'trip_id' => $trip->id, 'status' => 'draft', 'created_by' => $admin->id,
    ]);
    $poItem = PurchaseOrderItem::create([
        'purchase_order_id' => $po->id, 'product_id' => $product->id,
        'quantity_ordered' => 15, 'quantity_received' => 0, 'unit_cost' => 50000,
    ]);

    return compact('product', 'order', 'orderItem', 'po', 'poItem');
}

test('confirming arrival with too little stock splits the order item into arrived + sold_out', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $s = makePoScenario($admin, $trip, $customer);

    $this->actingAs($admin)->post(route('purchasing.arrival', $s['po']), [
        'items' => [['id' => $s['poItem']->id, 'quantity_received' => 3]],
    ]);

    $s['po']->refresh();
    expect($s['po']->status)->toBe('arrived');

    $items = OrderItem::where('order_id', $s['order']->id)->get();
    expect($items)->toHaveCount(2);
    expect($items->firstWhere('status', 'arrived')->quantity)->toBe(3);
    expect($items->firstWhere('status', 'sold_out')->quantity)->toBe(7);

    expect(ActivityLog::where('action', 'purchasing.arrival_confirmed')->count())->toBe(1);
});

test('correcting the received quantity reverses the wrong split and re-allocates fully — no PO deletion needed', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $s = makePoScenario($admin, $trip, $customer);

    // First (wrong) confirmation — understated, causes a bad split.
    $this->actingAs($admin)->post(route('purchasing.arrival', $s['po']), [
        'items' => [['id' => $s['poItem']->id, 'quantity_received' => 3]],
    ]);
    expect(OrderItem::where('order_id', $s['order']->id)->count())->toBe(2);

    // Correction — the real number was 15, enough to cover the full order.
    $response = $this->actingAs($admin)->post(route('purchasing.arrival', $s['po']), [
        'items' => [['id' => $s['poItem']->id, 'quantity_received' => 15]],
    ]);
    $response->assertRedirect();

    // The sold_out split row must be gone, and the original line restored
    // to its full quantity, fully arrived — not left as two rows.
    $items = OrderItem::where('order_id', $s['order']->id)->get();
    expect($items)->toHaveCount(1);
    expect($items->first()->status)->toBe('arrived');
    expect($items->first()->quantity)->toBe(10);

    expect($s['poItem']->fresh()->quantity_received)->toBe(15);

    expect(ActivityLog::where('action', 'purchasing.arrival_confirmed')->count())->toBe(1);
    expect(ActivityLog::where('action', 'purchasing.arrival_corrected')->count())->toBe(1);
});

test('the affected order total is recalculated correctly after a correction restores full quantity', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $s = makePoScenario($admin, $trip, $customer);

    $this->actingAs($admin)->post(route('purchasing.arrival', $s['po']), [
        'items' => [['id' => $s['poItem']->id, 'quantity_received' => 3]],
    ]);
    $partialTotal = $s['order']->fresh()->total_amount;

    $this->actingAs($admin)->post(route('purchasing.arrival', $s['po']), [
        'items' => [['id' => $s['poItem']->id, 'quantity_received' => 15]],
    ]);
    $correctedTotal = $s['order']->fresh()->total_amount;

    // Restoring the full 10 units (not just 3) must raise the order total
    // back up — proving the correction actually re-priced the order, not
    // just changed a status label.
    expect((float) $correctedTotal)->toBeGreaterThan((float) $partialTotal);
    expect((float) $correctedTotal)->toBe(500000.0);
});

test('a user without purchasing.edit permission cannot confirm or correct an arrival', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $s = makePoScenario($admin, $trip, $customer);
    $staff = $this->staffUser(); // role default: purchasing.edit = false

    $response = $this->actingAs($staff)->post(route('purchasing.arrival', $s['po']), [
        'items' => [['id' => $s['poItem']->id, 'quantity_received' => 15]],
    ]);

    $response->assertForbidden();
    expect($s['po']->fresh()->status)->toBe('draft');
});
