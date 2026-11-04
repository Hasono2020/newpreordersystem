<?php

use App\Models\CsAgent;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\OrderImportService;

/*
 * Product status "Deactivated": a product that has sold out (or is off sale) can't be picked
 * when creating an order, adding an item, or importing orders. Orders that already contain it
 * are left alone.
 */

class DeactivatedImportWriter
{
    use \App\Traits\HandlesXlsx;

    public function write(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'imp_') . '.xlsx';
        file_put_contents($path, $this->buildXlsx($rows));
        return $path;
    }
}

function dpProduct($trip, string $code, string $status = 'active'): Product
{
    return Product::create([
        'trip_id' => $trip->id, 'product_code' => $code,
        'price' => 100000, 'weight_gram' => 200, 'status' => $status,
    ]);
}

function dpOrderPayload(Product $product, CsAgent $agent, $trip, $customer): array
{
    return [
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'cs_agent_id' => $agent->id,
        'items' => [['product_id' => $product->id, 'product_variant_id' => null, 'quantity' => 1, 'unit_price' => 100000]],
        'client_token' => 'dp-token-' . uniqid(),
    ];
}

// ── Setting the status ──────────────────────────────────────────────

test('a product can be set to Deactivated, and the edit page offers it', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $supplier = Supplier::factory()->create();
    $product  = dpProduct($trip, 'DP100');
    $product->update(['supplier_id' => $supplier->id]);

    $this->actingAs($admin)->get(route('products.edit', $product))
        ->assertOk()->assertSee('Deactivated (sold out)');

    $this->actingAs($admin)->put(route('products.update', $product), [
        'trip_id' => $trip->id, 'product_code' => 'DP100', 'supplier_id' => $supplier->id,
        'price' => 100000, 'weight_gram' => 200, 'status' => 'deactivated',
    ])->assertSessionDoesntHaveErrors();

    expect($product->fresh()->status)->toBe('deactivated');
    expect($product->fresh()->isDeactivated())->toBeTrue();
});

test('a made-up status is still refused', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $supplier = Supplier::factory()->create();
    $product  = dpProduct($trip, 'DP101');

    $this->actingAs($admin)->put(route('products.update', $product), [
        'trip_id' => $trip->id, 'product_code' => 'DP101', 'supplier_id' => $supplier->id,
        'price' => 100000, 'weight_gram' => 200, 'status' => 'gone',
    ])->assertSessionHasErrors('status');

    expect($product->fresh()->status)->toBe('active');
});

// ── Creating an order ───────────────────────────────────────────────

test('the New Order product list leaves out deactivated products', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    dpProduct($trip, 'DPLIVE');
    dpProduct($trip, 'DPGONE', 'deactivated');
    dpProduct($trip, 'DPCLOSED', 'closed');   // closed/arrived keep working as before

    $codes = collect($this->actingAs($admin)->getJson(route('api.trip.products', $trip))->assertOk()->json())->pluck('code')->all();

    expect($codes)->toContain('DPLIVE')->toContain('DPCLOSED')->not->toContain('DPGONE');
});

test('an order with a deactivated product is refused and nothing is saved', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $agent    = CsAgent::factory()->create();
    $gone     = dpProduct($trip, 'DPGONE2', 'deactivated');

    $response = $this->actingAs($admin)->post(route('orders.store'), dpOrderPayload($gone, $agent, $trip, $customer));

    $response->assertSessionHasErrors('items');
    expect(session('errors')->first('items'))->toContain('DPGONE2');
    expect(Order::count())->toBe(0);
});

test('an order with an active product still saves', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $agent    = CsAgent::factory()->create();
    $live     = dpProduct($trip, 'DPLIVE2');

    $this->actingAs($admin)->post(route('orders.store'), dpOrderPayload($live, $agent, $trip, $customer))
        ->assertSessionDoesntHaveErrors();

    expect(Order::count())->toBe(1);
});

test('one deactivated product among several refuses the whole order', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $agent    = CsAgent::factory()->create();
    $live     = dpProduct($trip, 'DPLIVE3');
    $gone     = dpProduct($trip, 'DPGONE3', 'deactivated');

    $payload = dpOrderPayload($live, $agent, $trip, $customer);
    $payload['items'][] = ['product_id' => $gone->id, 'product_variant_id' => null, 'quantity' => 1, 'unit_price' => 100000];

    $this->actingAs($admin)->post(route('orders.store'), $payload)->assertSessionHasErrors('items');
    expect(Order::count())->toBe(0);
});

// ── Adding an item to an existing order ─────────────────────────────

function dpExistingOrder($test, $admin, $trip, Product $product): Order
{
    $order = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $test->customer($admin)->id, 'created_by' => $admin->id,
        'shipping_area_id' => null, 'order_number' => null,
        'subtotal' => 100000, 'total_amount' => 100000, 'deposit_paid' => 0, 'payment_status' => 'unpaid',
    ]);
    OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100000, 'line_total' => 100000, 'status' => 'pending']);
    return $order;
}

test('a deactivated product cannot be added to an existing order', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $live  = dpProduct($trip, 'DPLIVE4');
    $gone  = dpProduct($trip, 'DPGONE4', 'deactivated');
    $order = dpExistingOrder($this, $admin, $trip, $live);

    $this->actingAs($admin)->post(route('orders.items.add', $order), [
        'product_id' => $gone->id, 'quantity' => 1, 'unit_price' => 100000, 'client_token' => 'dp-add-' . uniqid(),
    ])->assertSessionHasErrors('product_id');

    expect($order->items()->count())->toBe(1);
});

test('the Add Item list on the order edit page leaves out deactivated products', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $live  = dpProduct($trip, 'DPSHOWN');
    dpProduct($trip, 'DPHIDDEN', 'deactivated');
    $order = dpExistingOrder($this, $admin, $trip, $live);

    $this->actingAs($admin)->get(route('orders.edit', $order))
        ->assertOk()->assertSee('DPSHOWN')->assertDontSee('DPHIDDEN');
});

test('an order that already has the product keeps it after the product is deactivated', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $product = dpProduct($trip, 'DPKEEP');
    $order   = dpExistingOrder($this, $admin, $trip, $product);

    $product->update(['status' => 'deactivated']);

    $this->actingAs($admin)->get(route('orders.show', $order))->assertOk()->assertSee('DPKEEP');
    expect($order->items()->count())->toBe(1);
    expect((float) $order->fresh()->total_amount)->toBe(100000.0);   // nothing re-priced or removed
});

// ── Importing orders ────────────────────────────────────────────────

test('an Excel import row for a deactivated product is flagged, an active one is fine', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    CsAgent::factory()->create(['name' => 'CS1 Endang']);
    dpProduct($trip, 'DPIMPOK');
    dpProduct($trip, 'DPIMPGONE', 'deactivated');

    $header = ['DIBUAT OLEH', 'NO', 'NAMA', 'IG/WA', 'NO HP', 'KOTA', 'KODE', 'WARNA', 'SIZE', 'HARGA SATUAN', 'DP', 'TGL DP', 'AN', 'KET'];
    $path = (new DeactivatedImportWriter())->write([
        $header,
        ['Admin', '1', 'Budi Santoso', 'CS1 Endang', '0812345', 'BATAM', 'DPIMPOK',   '', '', '100000', '', '', '', ''],
        ['Admin', '2', 'Siti Aminah',  'CS1 Endang', '0812346', 'BATAM', 'DPIMPGONE', '', '', '100000', '', '', '', ''],
    ]);

    $result = app(OrderImportService::class)->readAndValidate($path, $trip);

    expect($result['errors'])->toHaveCount(1);
    expect($result['errors'][0])->toContain('DPIMPGONE')->toContain('deactivated');
});
