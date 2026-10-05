<?php

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Trip;

/*
 * Reported: deleting a trip with zero sales orders (already allowed past
 * the existing order-count guard) but at least one Purchase Order crashed
 * with a raw foreign key error instead of failing cleanly. The cascade
 * chain is products.trip_id -> (cascade) -> product_variants.product_id
 * -> (cascade) -> blocked by purchase_order_items.product_variant_id,
 * which has no cascade/null behavior. Fixed by blocking trip deletion
 * upfront with a clear message when POs exist, same pattern already used
 * for sales orders, rather than letting the cascade fail partway through.
 */

test('deleting a trip with purchase orders is blocked with a clear message instead of a raw SQL error', function () {
    $admin   = $this->adminUser();
    $trip    = Trip::factory()->create(['created_by' => $admin->id]);
    $product = Product::create(['trip_id' => $trip->id, 'product_code' => 'TRIPDEL01', 'price' => 50000, 'weight_gram' => 100, 'status' => 'active']);
    $po = PurchaseOrder::create(['trip_id' => $trip->id, 'status' => 'draft', 'created_by' => $admin->id]);
    PurchaseOrderItem::create(['purchase_order_id' => $po->id, 'product_id' => $product->id, 'quantity_ordered' => 10, 'quantity_received' => 0, 'unit_cost' => 40000]);

    $response = $this->actingAs($admin)->delete(route('trips.destroy', $trip));

    $response->assertRedirect();
    $response->assertSessionHas('error');
    expect(Trip::find($trip->id))->not->toBeNull(); // not deleted
});

test('a trip with neither orders nor purchase orders still deletes normally', function () {
    $admin = $this->adminUser();
    $trip  = Trip::factory()->create(['created_by' => $admin->id]);

    $response = $this->actingAs($admin)->delete(route('trips.destroy', $trip));

    $response->assertRedirect();
    expect(Trip::find($trip->id))->toBeNull();
});
