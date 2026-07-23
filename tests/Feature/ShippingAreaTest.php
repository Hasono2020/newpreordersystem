<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\ShippingArea;

/*
 * Fix #5  — ShippingArea flat_fee import/export/template support.
 * Fix #11 — destroy() blocks deletion when the area is still in use.
 */

// ── Pure model tests (no HTTP) ────────────────────────────────────────

test('isFlatFee returns false when flat_fee is null', function () {
    $area = ShippingArea::factory()->create(['flat_fee' => null, 'price_per_kg' => 20000]);
    expect($area->isFlatFee())->toBeFalse();
});

test('isFlatFee returns false when flat_fee is zero', function () {
    $area = ShippingArea::factory()->create(['flat_fee' => 0, 'price_per_kg' => 20000]);
    expect($area->isFlatFee())->toBeFalse();
});

test('isFlatFee returns true when flat_fee is set and positive', function () {
    $area = ShippingArea::factory()->flatFee(10000)->create();
    expect($area->isFlatFee())->toBeTrue();
});

test('flat-fee area returns flat_fee for any non-zero weight', function () {
    $area = ShippingArea::factory()->flatFee(10000)->create();
    expect($area->calcShippingFee(1))->toBe(10000.0)
        ->and($area->calcShippingFee(500))->toBe(10000.0)
        ->and($area->calcShippingFee(5000))->toBe(10000.0);
});

test('calcShippingFee returns 0 for zero grams regardless of area type', function () {
    $flatArea  = ShippingArea::factory()->flatFee(10000)->create();
    $perKgArea = ShippingArea::factory()->create(['price_per_kg' => 20000]);
    expect($flatArea->calcShippingFee(0))->toBe(0.0)
        ->and($perKgArea->calcShippingFee(0))->toBe(0.0);
});

test('per-kg area calculates from weight', function () {
    $area = ShippingArea::factory()->create(['price_per_kg' => 20000, 'flat_fee' => null]);
    expect($area->calcShippingFee(500))->toBe(20000.0)
        ->and($area->calcShippingFee(1500))->toBe(40000.0);
});

test('getSubsidyCap on flat-fee area returns flat_fee_subsidy_cap when set', function () {
    $area = ShippingArea::factory()->flatFee(10000, 8000)->create();
    expect($area->getSubsidyCap())->toBe(8000.0);
});

test('getSubsidyCap on flat-fee area falls back to flat_fee when cap not set', function () {
    $area = ShippingArea::factory()->flatFee(10000)->create();
    expect($area->getSubsidyCap())->toBe(10000.0);
});

// ── Model-level DB tests for store/update (bypass HTTP entirely) ──────

test('creating a ShippingArea with flat_fee stores it correctly', function () {
    ShippingArea::create([
        'name'         => 'BATAM',
        'price_per_kg' => 0,
        'flat_fee'     => 10000,
        'is_active'    => true,
    ]);
    $area = ShippingArea::where('name', 'BATAM')->first();
    expect($area)->not->toBeNull()
        ->and($area->isFlatFee())->toBeTrue()
        ->and((float) $area->flat_fee)->toBe(10000.0);
});

test('updating an area from per-kg to flat stores flat_fee correctly', function () {
    $area = ShippingArea::factory()->create(['price_per_kg' => 25000, 'flat_fee' => null]);
    $area->update(['price_per_kg' => 0, 'flat_fee' => 10000]);
    $area->refresh();
    expect($area->isFlatFee())->toBeTrue()
        ->and((float) $area->flat_fee)->toBe(10000.0)
        ->and((float) $area->price_per_kg)->toBe(0.0);
});

test('flat_fee null or zero means per-kg pricing is used', function () {
    $area = ShippingArea::factory()->create(['price_per_kg' => 20000, 'flat_fee' => null]);
    expect($area->isFlatFee())->toBeFalse()
        ->and($area->calcShippingFee(500))->toBe(20000.0);
});

// ── ShippingAreaController validation via HTTP ────────────────────────

test('store validates flat_fee is required when pricing_mode is flat', function () {
    $admin = $this->adminUser();
    // POST with pricing_mode=flat but no flat_fee — controller sets required|numeric|min:1
    $this->actingAs($admin)
        ->post('/shipping', [
            'name'         => 'BATAM_NOFLAT',
            'pricing_mode' => 'flat',
            'flat_fee'     => null,
            'price_per_kg' => 0,
        ])->assertSessionHasErrors('flat_fee');
});

test('store creates flat-fee area via HTTP', function () {
    $admin = $this->adminUser();
    $this->actingAs($admin)
        ->post('/shipping', [
            'name'         => 'BATAM_HTTP',
            'pricing_mode' => 'flat',
            'flat_fee'     => 10000,
            'price_per_kg' => 0,
            'is_active'    => true,
        ])->assertRedirect('/shipping');

    $area = ShippingArea::where('name', 'BATAM_HTTP')->first();
    expect($area->isFlatFee())->toBeTrue()
        ->and((float) $area->flat_fee)->toBe(10000.0);
});

test('controller infers flat pricing_mode from filled flat_fee when field missing', function () {
    $admin = $this->adminUser();
    $this->actingAs($admin)
        ->post('/shipping', [
            'name'     => 'BATAM_INFER',
            'flat_fee' => 15000,
        ])->assertRedirect('/shipping');

    $area = ShippingArea::where('name', 'BATAM_INFER')->first();
    expect($area->isFlatFee())->toBeTrue()
        ->and((float) $area->flat_fee)->toBe(15000.0);
});

test('update switches area from per-kg to flat via HTTP (no existing orders)', function () {
    $admin = $this->adminUser();
    // Use unique name so no seeded data references this area
    $area  = ShippingArea::factory()->create([
        'name'         => 'AREA_SWITCH_' . uniqid(),
        'price_per_kg' => 25000,
        'flat_fee'     => null,
    ]);

    $this->actingAs($admin)
        ->put("/shipping/{$area->id}", [
            'name'         => $area->name,
            'pricing_mode' => 'flat',
            'flat_fee'     => 10000,
            'price_per_kg' => 0,
            'is_active'    => true,
        ])->assertRedirect('/shipping');

    $area->refresh();
    expect($area->isFlatFee())->toBeTrue()
        ->and((float) $area->flat_fee)->toBe(10000.0);
});

// ── Fix #11: destroy guard ───────────────────────────────────────────

test('destroy is blocked when area is used by customers', function () {
    $area = ShippingArea::factory()->create();
    Customer::factory()->create(['default_shipping_area_id' => $area->id]);

    $admin = $this->adminUser();
    $this->actingAs($admin)
        ->delete("/shipping/{$area->id}")
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(ShippingArea::find($area->id))->not->toBeNull();
});

test('destroy is blocked when area is used by orders', function () {
    $admin = $this->adminUser();
    $area  = ShippingArea::factory()->create();
    $trip  = $this->openTrip();
    $cust  = $this->customer($admin);
    Order::factory()->create([
        'trip_id'          => $trip->id,
        'customer_id'      => $cust->id,
        'shipping_area_id' => $area->id,
        'created_by'       => $admin->id,
    ]);

    $this->actingAs($admin)
        ->delete("/shipping/{$area->id}")
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(ShippingArea::find($area->id))->not->toBeNull();
});

test('destroy succeeds when area has no customers or orders', function () {
    // Create a fresh area with a unique name so no seeded data references it
    $area  = ShippingArea::factory()->create(['name' => 'UNUSED_AREA_' . uniqid()]);
    $admin = $this->adminUser();

    $this->actingAs($admin)
        ->delete("/shipping/{$area->id}")
        ->assertRedirect('/shipping')
        ->assertSessionHas('success');

    expect(ShippingArea::find($area->id))->toBeNull();
});