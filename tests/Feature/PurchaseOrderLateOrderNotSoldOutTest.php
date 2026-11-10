<?php

use App\Models\OrderItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;

/*
 * Reported: PO line IVORY / S ordered 9, received 9 (nothing wrong), yet one
 * IVORY / S order became Sold Out when staff corrected a DIFFERENT line
 * (IVORY / M, received 2 of 6).
 *
 * Cause: the FIFO pool was every pending order of the variant in the trip,
 * including orders placed after the PO was built. Those had no stock
 * requested for them, but being newest in FIFO they were marked sold out.
 *
 * Fix: only orders within the PO line's quantity_ordered can be sold out by
 * this PO. Later orders stay pending (uncovered demand for the next PO).
 */

function lateOrderItem($admin, $trip, $customer, $product, $variant, int $qty, $orderedAt): OrderItem
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

test('orders placed after the PO was built are left pending, not sold out', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);

    $product = Product::create([
        'trip_id' => $trip->id, 'product_code' => 'TB01',
        'price' => 50000, 'weight_gram' => 100, 'status' => 'active',
    ]);
    $ivoryS = ProductVariant::create(['product_id' => $product->id, 'color' => 'IVORY', 'size' => 'S', 'price_adjustment' => 0, 'supplier_stock' => 0, 'allocated_qty' => 0, 'is_active' => true]);
    $ivoryM = ProductVariant::create(['product_id' => $product->id, 'color' => 'IVORY', 'size' => 'M', 'price_adjustment' => 0, 'supplier_stock' => 0, 'allocated_qty' => 0, 'is_active' => true]);

    // IVORY / S: 9 units ordered before the PO, +1 unit ordered after it.
    $sEarly = lateOrderItem($admin, $trip, $customer, $product, $ivoryS, 9, now()->subHours(3));
    $sLate  = lateOrderItem($admin, $trip, $customer, $product, $ivoryS, 1, now()->subMinutes(5));
    // IVORY / M: 6 units, supplier only delivers 2.
    $mItem  = lateOrderItem($admin, $trip, $customer, $product, $ivoryM, 6, now()->subHours(2));

    $po = PurchaseOrder::create(['trip_id' => $trip->id, 'status' => 'submitted', 'created_by' => $admin->id]);
    $poS = PurchaseOrderItem::create(['purchase_order_id' => $po->id, 'product_id' => $product->id, 'product_variant_id' => $ivoryS->id, 'quantity_ordered' => 9, 'quantity_received' => 0, 'unit_cost' => 50000]);
    $poM = PurchaseOrderItem::create(['purchase_order_id' => $po->id, 'product_id' => $product->id, 'product_variant_id' => $ivoryM->id, 'quantity_ordered' => 6, 'quantity_received' => 0, 'unit_cost' => 50000]);

    $this->actingAs($admin)->post(route('purchasing.arrival', $po), [
        'items' => [
            ['id' => $poS->id, 'quantity_received' => 9],
            ['id' => $poM->id, 'quantity_received' => 2],
        ],
    ])->assertRedirect();

    // IVORY / S: fully received -> early order arrived, late order untouched.
    expect($sEarly->fresh()->status)->toBe('arrived');
    expect($sLate->fresh()->status)->toBe('pending');

    // IVORY / M: shortfall still handled — 2 arrived, 4 sold out.
    $mRows = OrderItem::where('order_id', $mItem->order_id)->get();
    expect($mRows->firstWhere('status', 'arrived')->quantity)->toBe(2);
    expect($mRows->firstWhere('status', 'sold_out')->quantity)->toBe(4);
});

test('a real shortfall within PO coverage is still sold out (FIFO, newest first)', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);

    $product = Product::create([
        'trip_id' => $trip->id, 'product_code' => 'TB02',
        'price' => 50000, 'weight_gram' => 100, 'status' => 'active',
    ]);
    $v = ProductVariant::create(['product_id' => $product->id, 'color' => 'BLACK', 'size' => 'M', 'price_adjustment' => 0, 'supplier_stock' => 0, 'allocated_qty' => 0, 'is_active' => true]);

    $first  = lateOrderItem($admin, $trip, $customer, $product, $v, 3, now()->subHours(3));
    $second = lateOrderItem($admin, $trip, $customer, $product, $v, 2, now()->subHours(2));

    $po   = PurchaseOrder::create(['trip_id' => $trip->id, 'status' => 'submitted', 'created_by' => $admin->id]);
    $poIt = PurchaseOrderItem::create(['purchase_order_id' => $po->id, 'product_id' => $product->id, 'product_variant_id' => $v->id, 'quantity_ordered' => 5, 'quantity_received' => 0, 'unit_cost' => 50000]);

    $this->actingAs($admin)->post(route('purchasing.arrival', $po), [
        'items' => [['id' => $poIt->id, 'quantity_received' => 3]],
    ])->assertRedirect();

    expect($first->fresh()->status)->toBe('arrived');
    expect($second->fresh()->status)->toBe('sold_out');
});
