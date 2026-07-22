<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\ShippingArea;

/*
 * Fix #5  — ShippingArea import/export/template includes flat_fee +
 *            flat_fee_subsidy_cap columns and infers pricing_mode correctly.
 * Fix #11 — destroy() blocks deletion when the area is still in use by
 *            customers or orders.
 */

// ── isFlatFee / calcShippingFee model helpers ────────────────────────

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

test('flat-fee area always returns flat_fee regardless of weight', function () {
    $area = ShippingArea::factory()->flatFee(10000)->create();
    expect($area->calcShippingFee(0))->toBe(10000.0)
        ->and($area->calcShippingFee(500))->toBe(10000.0)
        ->and($area->calcShippingFee(5000))->toBe(10000.0);
});

test('per-kg area calculates from weight', function () {
    $area = ShippingArea::factory()->create(['price_per_kg' => 20000, 'flat_fee' => null]);
    // 500g -> 1kg -> 20000 ; 1500g -> 2kg -> 40000
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

// ── Create / Edit through UI ─────────────────────────────────────────

test('admin can create a flat-fee shipping area', function () {
    $admin = $this->adminUser();

    $this->actingAs($admin)->post('/shipping', [
        'name'         => 'BATAM',
        'province'     => '',
        'pricing_mode' => 'flat',
        'flat_fee'     => 10000,
        'price_per_kg' => 0,
        'is_active'    => true,
    ])->assertRedirect('/shipping');

    $area = ShippingArea::where('name', 'BATAM')->first();
    expect($area)->not->toBeNull()
        ->and($area->isFlatFee())->toBeTrue()
        ->and((float) $area->flat_fee)->toBe(10000.0)
        ->and((float) $area->price_per_kg)->toBe(0.0);
});

test('creating flat-fee area without flat_fee value fails validation', function () {
    $admin = $this->adminUser();

    $this->actingAs($admin)->post('/shipping', [
        'name'         => 'BATAM2',
        'pricing_mode' => 'flat',
        'flat_fee'     => '',   // missing
        'price_per_kg' => 0,
    ])->assertSessionHasErrors('flat_fee');
});

test('admin can switch existing area from per-kg to flat fee', function () {
    $admin = $this->adminUser();
    $area  = ShippingArea::factory()->create(['price_per_kg' => 25000, 'flat_fee' => null]);

    $this->actingAs($admin)->put("/shipping/{$area->id}", [
        'name'         => $area->name,
        'pricing_mode' => 'flat',
        'flat_fee'     => 10000,
        'price_per_kg' => 0,
        'is_active'    => true,
    ])->assertRedirect('/shipping');

    $area->refresh();
    expect($area->isFlatFee())->toBeTrue()
        ->and((float) $area->flat_fee)->toBe(10000.0)
        ->and((float) $area->price_per_kg)->toBe(0.0);
});

test('controller infers flat pricing_mode from filled flat_fee when pricing_mode missing from POST', function () {
    $admin = $this->adminUser();

    // Simulate a form that omits pricing_mode but sends flat_fee
    $this->actingAs($admin)->post('/shipping', [
        'name'     => 'BATAM3',
        'flat_fee' => 15000,
        // no pricing_mode key
    ])->assertRedirect('/shipping');

    $area = ShippingArea::where('name', 'BATAM3')->first();
    expect($area->isFlatFee())->toBeTrue()
        ->and((float) $area->flat_fee)->toBe(15000.0);
});

// ── Fix #11: destroy guard ───────────────────────────────────────────

test('deleting an area used by customers is blocked', function () {
    $admin = $this->adminUser();
    $area  = ShippingArea::factory()->create();
    Customer::factory()->create(['default_shipping_area_id' => $area->id]);

    $this->actingAs($admin)->delete("/shipping/{$area->id}")
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(ShippingArea::find($area->id))->not->toBeNull();
});

test('deleting an area used by orders is blocked', function () {
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

    $this->actingAs($admin)->delete("/shipping/{$area->id}")
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(ShippingArea::find($area->id))->not->toBeNull();
});

test('deleting an unused area succeeds', function () {
    $admin = $this->adminUser();
    $area  = ShippingArea::factory()->create();

    $this->actingAs($admin)->delete("/shipping/{$area->id}")
        ->assertRedirect('/shipping')
        ->assertSessionHas('success');

    expect(ShippingArea::find($area->id))->toBeNull();
});