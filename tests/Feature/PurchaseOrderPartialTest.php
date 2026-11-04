<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;

/*
 * A purchase order can cover just SOME of a supplier's products (the form has a tick box per
 * product). The products left out are not on the PO and stay as uncovered demand, ready for a
 * later PO — which merges into the same PO (one active PO per supplier per trip).
 *
 * The tick boxes work by disabling the left-out products' fields so the browser doesn't send
 * them; and a line sent with quantity 0 is skipped, not an error.
 */

/** A supplier with two products (SA01, SA02), each with one pending order line of $qty units. */
function poScenario($test, int $qty = 5): array
{
    $admin    = $test->adminUser();
    $trip     = $test->openTrip();
    $supplier = Supplier::factory()->create(['name' => 'SA']);
    $customer = $test->customer($admin);

    $products = [];
    foreach (['SA01', 'SA02'] as $code) {
        $products[$code] = Product::create([
            'trip_id' => $trip->id, 'product_code' => $code, 'supplier_id' => $supplier->id,
            'price' => 100000, 'weight_gram' => 100, 'status' => 'active',
        ]);
        $order = Order::factory()->create([
            'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
            'shipping_area_id' => null, 'order_number' => null,
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $products[$code]->id, 'quantity' => $qty,
            'unit_price' => 100000, 'line_total' => $qty * 100000, 'status' => 'pending',
        ]);
    }

    return compact('admin', 'trip', 'supplier', 'products');
}

function poPost($test, array $s, array $lines)
{
    $items = [];
    foreach ($lines as $code => $qty) {
        $items[] = ['product_id' => $s['products'][$code]->id, 'product_variant_id' => '', 'quantity_ordered' => $qty, 'unit_cost' => 50000];
    }
    return $test->actingAs($s['admin'])->post(route('purchasing.store'), [
        'trip_id' => $s['trip']->id, 'supplier_id' => $s['supplier']->id, 'items' => $items,
    ]);
}

function poDemandCodes($test, array $s): array
{
    $json = $test->actingAs($s['admin'])->getJson(route('purchasing.demand', ['trip_id' => $s['trip']->id]))->assertOk()->json();
    return collect($json)->flatMap(fn ($g) => collect($g['rows'])->pluck('product_code'))->unique()->sort()->values()->all();
}

test('a PO with only SA01 sent has only SA01 on it', function () {
    $s = poScenario($this);

    poPost($this, $s, ['SA01' => 5])->assertSessionDoesntHaveErrors();

    $po = PurchaseOrder::firstOrFail();
    expect($po->items()->pluck('product_id')->all())->toBe([$s['products']['SA01']->id]);
    expect((int) $po->items()->first()->quantity_ordered)->toBe(5);
});

test('SA02 stays as uncovered demand after a PO for SA01, and SA01 is covered', function () {
    $s = poScenario($this);
    expect(poDemandCodes($this, $s))->toBe(['SA01', 'SA02']);

    poPost($this, $s, ['SA01' => 5]);

    expect(poDemandCodes($this, $s))->toBe(['SA02']);
});

test('a later PO for SA02 merges into the same PO', function () {
    $s = poScenario($this);

    poPost($this, $s, ['SA01' => 5]);
    poPost($this, $s, ['SA02' => 5]);

    expect(PurchaseOrder::count())->toBe(1);
    $po = PurchaseOrder::firstOrFail();
    expect($po->items()->count())->toBe(2);
    expect(poDemandCodes($this, $s))->toBe([]);
});

test('a line left at 0 is skipped instead of failing the whole PO', function () {
    $s = poScenario($this);

    poPost($this, $s, ['SA01' => 5, 'SA02' => 0])->assertSessionDoesntHaveErrors();

    $po = PurchaseOrder::firstOrFail();
    expect($po->items()->count())->toBe(1);
    expect($po->items()->first()->product_id)->toBe($s['products']['SA01']->id);
});

test('a PO with nothing to order is refused and creates nothing', function () {
    $s = poScenario($this);

    poPost($this, $s, ['SA01' => 0, 'SA02' => 0])->assertSessionHasErrors('items');

    expect(PurchaseOrder::count())->toBe(0);
});

test('the PO total only counts the lines that were ordered', function () {
    $s = poScenario($this);

    poPost($this, $s, ['SA01' => 4, 'SA02' => 0]);

    expect((float) PurchaseOrder::firstOrFail()->total_amount)->toBe(4 * 50000.0);
});
