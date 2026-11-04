<?php

use App\Models\CsAgent;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;

/*
 * Deactivating ONE variant (e.g. "White / L" sold out) — the other variants and the product
 * stay orderable; orders that already contain the variant are untouched.
 */

function pvProduct($trip, string $code): Product
{
    return Product::create([
        'trip_id' => $trip->id, 'product_code' => $code,
        'price' => 100000, 'weight_gram' => 200, 'status' => 'active',
    ]);
}

function pvVariant(Product $p, string $color, string $size, bool $active = true): ProductVariant
{
    return ProductVariant::create([
        'product_id' => $p->id, 'color' => $color, 'size' => $size,
        'price_adjustment' => 0, 'supplier_stock' => 0, 'allocated_qty' => 0, 'is_active' => $active,
    ]);
}

function pvPayload(Product $p, ?ProductVariant $v, CsAgent $agent, $trip, $customer): array
{
    return [
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'cs_agent_id' => $agent->id,
        'items' => [['product_id' => $p->id, 'product_variant_id' => $v?->id, 'quantity' => 1, 'unit_price' => 100000]],
        'client_token' => 'pv-token-' . uniqid(),
    ];
}

test('existing and new variants default to active', function () {
    $trip = $this->openTrip();
    $p = pvProduct($trip, 'PV001');
    $v = ProductVariant::create(['product_id' => $p->id, 'color' => 'Red', 'size' => 'M']);

    expect($v->fresh()->is_active)->toBeTrue();
});

test('a variant can be deactivated and reactivated from the product page, and it is logged', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $p     = pvProduct($trip, 'PV002');
    $v     = pvVariant($p, 'White', 'L');

    $this->actingAs($admin)->patch(route('products.variants.active', [$p, $v]))->assertSessionHas('success');
    expect($v->fresh()->is_active)->toBeFalse();

    $this->actingAs($admin)->get(route('products.show', $p))->assertOk()->assertSee('Deactivated')->assertSee('Reactivate');

    $this->actingAs($admin)->patch(route('products.variants.active', [$p, $v]));
    expect($v->fresh()->is_active)->toBeTrue();

    expect(\App\Models\ActivityLog::where('action', 'variant.deactivated')->exists())->toBeTrue();
    expect(\App\Models\ActivityLog::where('action', 'variant.reactivated')->exists())->toBeTrue();
});

test('a variant cannot be toggled through another product', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $a = pvProduct($trip, 'PV003A');
    $b = pvProduct($trip, 'PV003B');
    $v = pvVariant($a, 'White', 'L');

    $this->actingAs($admin)->patch(route('products.variants.active', [$b, $v]))->assertNotFound();
    expect($v->fresh()->is_active)->toBeTrue();
});

test('the New Order product list leaves out only the deactivated variant', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $p = pvProduct($trip, 'PV004');
    pvVariant($p, 'White', 'M');
    pvVariant($p, 'White', 'L', false);

    $row = collect($this->actingAs($admin)->getJson(route('api.trip.products', $trip))->assertOk()->json())->firstWhere('code', 'PV004');

    expect(collect($row['variants'])->pluck('label')->all())->toBe(['White / M']);
});

test('a product whose every variant is deactivated disappears from the list', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $gone  = pvProduct($trip, 'PV005');
    pvVariant($gone, 'White', 'M', false);
    pvVariant($gone, 'White', 'L', false);
    $plain = pvProduct($trip, 'PV006'); // no variants at all → still orderable

    $codes = collect($this->actingAs($admin)->getJson(route('api.trip.products', $trip))->json())->pluck('code')->all();

    expect($codes)->toContain('PV006')->not->toContain('PV005');
});

test('an order for a deactivated variant is refused and nothing is saved', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $agent    = CsAgent::factory()->create();
    $p = pvProduct($trip, 'PV007');
    $off = pvVariant($p, 'White', 'L', false);

    $this->actingAs($admin)->post(route('orders.store'), pvPayload($p, $off, $agent, $trip, $customer))
        ->assertSessionHasErrors('items');

    expect(session('errors')->first('items'))->toContain('PV007')->toContain('White / L');
    expect(Order::count())->toBe(0);
});

test('an order for an active variant of the same product still saves', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $agent    = CsAgent::factory()->create();
    $p  = pvProduct($trip, 'PV008');
    $on = pvVariant($p, 'White', 'M');
    pvVariant($p, 'White', 'L', false);

    $this->actingAs($admin)->post(route('orders.store'), pvPayload($p, $on, $agent, $trip, $customer))
        ->assertSessionDoesntHaveErrors();

    expect(Order::count())->toBe(1);
});

test('adding an item with a deactivated variant to an existing order is refused', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $agent    = CsAgent::factory()->create();
    $p   = pvProduct($trip, 'PV009');
    $on  = pvVariant($p, 'Black', 'M');
    $off = pvVariant($p, 'White', 'L', false);

    $this->actingAs($admin)->post(route('orders.store'), pvPayload($p, $on, $agent, $trip, $customer));
    $order = Order::first();
    $before = $order->items()->count();

    $this->actingAs($admin)->post(route('orders.items.add', $order), [
        'product_id' => $p->id, 'product_variant_id' => $off->id, 'quantity' => 1, 'unit_price' => 100000,
    ])->assertSessionHasErrors('product_variant_id');

    expect($order->items()->count())->toBe($before);
});

test('an order that already holds the variant is untouched when it is deactivated later', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $agent    = CsAgent::factory()->create();
    $p = pvProduct($trip, 'PV010');
    $v = pvVariant($p, 'White', 'L');

    $this->actingAs($admin)->post(route('orders.store'), pvPayload($p, $v, $agent, $trip, $customer));
    $this->actingAs($admin)->patch(route('products.variants.active', [$p, $v]));

    $this->actingAs($admin)->get(route('orders.show', Order::first()))->assertOk();
    expect(Order::first()->items()->first()->product_variant_id)->toBe($v->id);
});
