<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;

/*
 * Reported: a 2nd PO (late orders) for the same supplier/trip had a line set
 * to 0 received, but those orders stayed Pending instead of Sold Out.
 *
 * Cause: correcting/re-confirming one arrived PO reversed EVERY arrived /
 * sold_out order item of the same variants in the trip — including the ones
 * allocated by the OTHER PO — and zeroed the variant stock. Allocation is now
 * remembered per PO (order_items.arrival_po_id) and a reversal only undoes its
 * own PO.
 */

function mpoOrderItem($admin, $trip, $customer, $product, $variant, int $qty, $orderedAt): OrderItem
{
    $order = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id,
        'created_by' => $admin->id, 'ordered_at' => $orderedAt,
    ]);

    return OrderItem::create([
        'order_id' => $order->id, 'product_id' => $product->id,
        'product_variant_id' => $variant->id, 'quantity' => $qty,
        'unit_price' => 50000, 'line_total' => 50000 * $qty, 'status' => 'pending',
    ]);
}

function mpoPo($admin, $trip, $product, $variant, int $ordered): array
{
    $po = PurchaseOrder::create(['trip_id' => $trip->id, 'status' => 'submitted', 'created_by' => $admin->id]);
    $it = PurchaseOrderItem::create([
        'purchase_order_id' => $po->id, 'product_id' => $product->id,
        'product_variant_id' => $variant->id, 'quantity_ordered' => $ordered,
        'quantity_received' => 0, 'unit_cost' => 50000,
    ]);

    return [$po, $it];
}

test('a second PO with 0 received sells out its own orders, and correcting it never touches the first PO', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);

    $product = Product::create([
        'trip_id' => $trip->id, 'product_code' => 'TB01',
        'price' => 50000, 'weight_gram' => 100, 'status' => 'active',
    ]);
    $v = ProductVariant::create(['product_id' => $product->id, 'color' => 'IVORY', 'size' => 'S', 'price_adjustment' => 0, 'supplier_stock' => 0, 'allocated_qty' => 0, 'is_active' => true]);

    // PO 1 demand: 3 + 3 units, supplier delivers 3 -> A arrived, B sold out.
    $a = mpoOrderItem($admin, $trip, $customer, $product, $v, 3, now()->subHours(5));
    $b = mpoOrderItem($admin, $trip, $customer, $product, $v, 3, now()->subHours(4));
    [$po1, $po1Item] = mpoPo($admin, $trip, $product, $v, 6);

    $this->actingAs($admin)->post(route('purchasing.arrival', $po1), [
        'items' => [['id' => $po1Item->id, 'quantity_received' => 3]],
    ])->assertRedirect();

    // Late orders -> PO 2 (ordered 3), supplier delivers nothing.
    $c = mpoOrderItem($admin, $trip, $customer, $product, $v, 2, now()->subHours(2));
    $d = mpoOrderItem($admin, $trip, $customer, $product, $v, 1, now()->subHour());
    [$po2, $po2Item] = mpoPo($admin, $trip, $product, $v, 3);

    $this->actingAs($admin)->post(route('purchasing.arrival', $po2), [
        'items' => [['id' => $po2Item->id, 'quantity_received' => 0]],
    ])->assertRedirect();

    expect($a->fresh()->status)->toBe('arrived');
    expect($b->fresh()->status)->toBe('sold_out');
    expect($c->fresh()->status)->toBe('sold_out');
    expect($d->fresh()->status)->toBe('sold_out');

    // Correct PO 2 (really 2 arrived): PO 1's results must survive untouched.
    $this->actingAs($admin)->post(route('purchasing.arrival', $po2), [
        'items' => [['id' => $po2Item->id, 'quantity_received' => 2]],
    ])->assertRedirect();

    expect($a->fresh()->status)->toBe('arrived');
    expect($b->fresh()->status)->toBe('sold_out');
    expect($c->fresh()->status)->toBe('arrived');
    expect($d->fresh()->status)->toBe('sold_out');

    // Stock = what BOTH POs received, not just the last one.
    expect($v->fresh()->supplier_stock)->toBe(5);
    expect($v->fresh()->allocated_qty)->toBe(5);
});
