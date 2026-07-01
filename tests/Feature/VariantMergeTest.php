<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;

/*
 * Tests for ProductController::mergeVariant — merging a duplicate variant
 * into a survivor: order items + PO items reassigned, allocated stock summed,
 * duplicate deleted, cross-product merge blocked.
 */

// ── Local builders ───────────────────────────────────────────────────

function mergeProduct($test): Product
{
    $trip = $test->openTrip();
    return Product::create([
        'trip_id' => $trip->id, 'product_code' => 'AA_' . fake()->unique()->numerify('###'),
        'price' => 100000, 'weight_gram' => 200, 'status' => 'active',
    ]);
}

function mergeVariantRow(Product $product, string $color, string $size, int $stock = 0, int $alloc = 0): ProductVariant
{
    return $product->variants()->create([
        'color' => $color, 'size' => $size,
        'price_adjustment' => 0, 'supplier_stock' => $stock, 'allocated_qty' => $alloc,
    ]);
}

function mergeOrderItem($test, Product $product, ProductVariant $variant, ?\App\Models\Customer $cust = null): OrderItem
{
    $admin = $test->adminUser();
    $cust  = $cust ?? $test->customer($admin);
    $order = Order::factory()->create([
        'trip_id' => $product->trip_id, 'customer_id' => $cust->id,
        'created_by' => $admin->id, 'shipping_area_id' => $test->shippingArea()->id,
        'subtotal' => 100000, 'total_amount' => 100000,
    ]);
    return OrderItem::create([
        'order_id' => $order->id, 'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'quantity' => 1, 'unit_price' => 100000, 'line_total' => 100000, 'status' => 'pending',
    ]);
}

// ── Core merge behaviour ─────────────────────────────────────────────

test('merging moves order items from duplicate to survivor and deletes the duplicate', function () {
    $admin   = $this->adminUser();
    $product = mergeProduct($this);
    $keep    = mergeVariantRow($product, 'PINK', 'XL');   // survivor
    $dupe    = mergeVariantRow($product, 'PINK', 'XL');   // duplicate

    // 2 orders on the survivor, 1 on the duplicate
    mergeOrderItem($this, $product, $keep);
    mergeOrderItem($this, $product, $keep);
    $dupeItem = mergeOrderItem($this, $product, $dupe);

    $this->actingAs($admin)
        ->post("/products/{$product->id}/variants/{$dupe->id}/merge", ['survivor_id' => $keep->id])
        ->assertRedirect();

    // Duplicate gone
    expect(ProductVariant::find($dupe->id))->toBeNull();
    // The duplicate's order item now points at the survivor
    expect($dupeItem->fresh()->product_variant_id)->toBe($keep->id);
    // Survivor now has all 3 order items
    expect(OrderItem::where('product_variant_id', $keep->id)->count())->toBe(3);
});

test('merging reassigns purchase-order items to the survivor', function () {
    $admin   = $this->adminUser();
    $product = mergeProduct($this);
    $keep    = mergeVariantRow($product, 'BLUE', 'M');
    $dupe    = mergeVariantRow($product, 'BLUE', 'M');

    // A PO with an item pointing at the duplicate variant
    $po = PurchaseOrder::create([
        'trip_id' => $product->trip_id, 'created_by' => $admin->id,
        'purchased_at' => now()->toDateString(), 'status' => 'draft', 'total_amount' => 0,
    ]);
    $poItem = PurchaseOrderItem::create([
        'purchase_order_id' => $po->id, 'product_id' => $product->id,
        'product_variant_id' => $dupe->id,
        'quantity_ordered' => 5, 'quantity_received' => 0, 'unit_cost' => 0, 'line_total' => 0,
    ]);

    $this->actingAs($admin)
        ->post("/products/{$product->id}/variants/{$dupe->id}/merge", ['survivor_id' => $keep->id])
        ->assertRedirect();

    expect($poItem->fresh()->product_variant_id)->toBe($keep->id)
        ->and(ProductVariant::find($dupe->id))->toBeNull();
});

test('merging sums the allocated stock onto the survivor', function () {
    $admin   = $this->adminUser();
    $product = mergeProduct($this);
    $keep    = mergeVariantRow($product, 'RED', 'L', stock: 10, alloc: 3);
    $dupe    = mergeVariantRow($product, 'RED', 'L', stock: 7,  alloc: 4);

    $this->actingAs($admin)
        ->post("/products/{$product->id}/variants/{$dupe->id}/merge", ['survivor_id' => $keep->id])
        ->assertRedirect();

    // allocated summed: 3 + 4 = 7 ; survivor keeps its own supplier_stock (10)
    $keep->refresh();
    expect((int) $keep->allocated_qty)->toBe(7)
        ->and((int) $keep->supplier_stock)->toBe(10);
});

// ── Guard rails ──────────────────────────────────────────────────────

test('cannot merge a variant into itself', function () {
    $admin   = $this->adminUser();
    $product = mergeProduct($this);
    $v       = mergeVariantRow($product, 'GREEN', 'S');

    // survivor_id == variant id -> controller rejects with an error, does not delete
    $this->actingAs($admin)
        ->post("/products/{$product->id}/variants/{$v->id}/merge", ['survivor_id' => $v->id])
        ->assertRedirect();

    expect(ProductVariant::find($v->id))->not->toBeNull(); // still there
});

test('cannot merge into a variant from a different product', function () {
    $admin    = $this->adminUser();
    $productA = mergeProduct($this);
    $productB = mergeProduct($this);
    $dupe     = mergeVariantRow($productA, 'BLACK', 'M');
    $foreign  = mergeVariantRow($productB, 'BLACK', 'M'); // belongs to a DIFFERENT product

    $this->actingAs($admin)
        ->post("/products/{$productA->id}/variants/{$dupe->id}/merge", ['survivor_id' => $foreign->id])
        ->assertRedirect(); // returns back with an error, does not merge

    // Both variants still exist — nothing was merged across products
    expect(ProductVariant::find($dupe->id))->not->toBeNull()
        ->and(ProductVariant::find($foreign->id))->not->toBeNull();
});

test('staff without products.edit permission cannot merge', function () {
    $staff   = $this->staffUser(['permissions' => ['products.edit' => false]]);
    $product = mergeProduct($this);
    $keep    = mergeVariantRow($product, 'GREY', 'M');
    $dupe    = mergeVariantRow($product, 'GREY', 'M');

    $this->actingAs($staff)
        ->post("/products/{$product->id}/variants/{$dupe->id}/merge", ['survivor_id' => $keep->id])
        ->assertStatus(403);

    expect(ProductVariant::find($dupe->id))->not->toBeNull();
});

test('merge writes an activity log entry', function () {
    $admin   = $this->adminUser();
    $product = mergeProduct($this);
    $keep    = mergeVariantRow($product, 'PINK', 'XL');
    $dupe    = mergeVariantRow($product, 'PINK', 'XL');

    $this->actingAs($admin)
        ->post("/products/{$product->id}/variants/{$dupe->id}/merge", ['survivor_id' => $keep->id]);

    $log = \App\Models\ActivityLog::where('action', 'variant.merged')->first();
    expect($log)->not->toBeNull()
        ->and($log->subject_type)->toBe('product')
        ->and($log->subject_id)->toBe($product->id);
});