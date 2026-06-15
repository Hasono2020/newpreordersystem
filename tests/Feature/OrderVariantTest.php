<?php
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\CsAgent;

test('order with variant product requires variant selection', function () {
    $staff    = $this->staffUser();
    $trip     = $this->openTrip();
    $area     = $this->shippingArea();
    $cust     = $this->customer($staff);
    $cs       = CsAgent::factory()->create();
    $supplier = Supplier::create(['name' => 'Supplier', 'contact' => null, 'notes' => null]);

    $product = Product::create([
        'trip_id'      => $trip->id,
        'supplier_id'  => $supplier->id,
        'product_code' => 'BJ_01',
        'price'        => 250000,
        'weight_gram'  => 350,
        'status'       => 'active',
    ]);

    ProductVariant::create([
        'product_id' => $product->id,
        'color'      => 'Merah',
        'size'       => 'M',
        'price_adjustment' => 0,
    ]);

    // Submit order without variant_id
    $this->actingAs($staff)->post('/orders', [
        'trip_id'          => $trip->id,
        'customer_id'      => $cust->id,
        'shipping_area_id' => $area->id,
        'cs_agent_id'      => $cs->id,
        'ordered_at'       => now()->format('Y-m-d\TH:i'),
        'items'            => [
            ['product_id' => $product->id, 'product_variant_id' => '', 'quantity' => 1, 'unit_price' => 250000],
        ],
    ])->assertSessionHasErrors('items');
});

test('order without variants saves successfully', function () {
    $staff    = $this->staffUser();
    $trip     = $this->openTrip();
    $area     = $this->shippingArea();
    $cust     = $this->customer($staff);
    $cs       = CsAgent::factory()->create();
    $supplier = Supplier::create(['name' => 'Supplier', 'contact' => null, 'notes' => null]);

    $product = Product::create([
        'trip_id'      => $trip->id,
        'supplier_id'  => $supplier->id,
        'product_code' => 'BP_01',
        'price'        => 200000,
        'weight_gram'  => 300,
        'status'       => 'active',
    ]);

    $this->actingAs($staff)->post('/orders', [
        'trip_id'          => $trip->id,
        'customer_id'      => $cust->id,
        'shipping_area_id' => $area->id,
        'cs_agent_id'      => $cs->id,
        'ordered_at'       => now()->format('Y-m-d\TH:i'),
        'items'            => [
            ['product_id' => $product->id, 'product_variant_id' => '', 'quantity' => 2, 'unit_price' => 200000],
        ],
    ])->assertRedirect();

    expect(Order::where('customer_id', $cust->id)->count())->toBe(1);
});