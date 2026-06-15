<?php
use App\Models\User;

// ── Role defaults ────────────────────────────────────────────────────

test('admin has all permissions', function () {
    $admin = $this->adminUser();
    expect($admin->hasPermission('orders.view'))->toBeTrue()
        ->and($admin->hasPermission('payments.verify'))->toBeTrue()
        ->and($admin->hasPermission('purchasing.edit'))->toBeTrue();
});

test('admin own_data is false (sees all data)', function () {
    $admin = $this->adminUser();
    expect($admin->isOwnDataOnly())->toBeFalse();
});

test('staff role has own_data true by default', function () {
    $staff = $this->staffUser();
    expect($staff->isOwnDataOnly())->toBeTrue();
});

test('staff cannot verify payments', function () {
    $staff = $this->staffUser();
    expect($staff->hasPermission('payments.verify'))->toBeFalse();
});

test('finance can verify payments', function () {
    $finance = $this->financeUser();
    expect($finance->hasPermission('payments.verify'))->toBeTrue();
});

test('staff cannot access purchasing edit', function () {
    $staff = $this->staffUser();
    expect($staff->hasPermission('purchasing.edit'))->toBeFalse();
});

test('staff without orders.create permission cannot create orders', function () {
    $staff = User::factory()->staff()->create(['permissions' => ['orders.create' => false]]);
    expect($staff->hasPermission('orders.create'))->toBeFalse();
});

test('per-user permission override works', function () {
    $staff = User::factory()->staff()->create([
        'permissions' => ['payments.verify' => true],
    ]);
    expect($staff->hasPermission('payments.verify'))->toBeTrue();
});

test('inactive user has no permissions', function () {
    $user = User::factory()->inactive()->create();
    expect($user->hasPermission('orders.view'))->toBeFalse();
});

// ── Route access ─────────────────────────────────────────────────────

test('staff without orders.create permission cannot access order create page', function () {
    $staff = User::factory()->staff()->create(['permissions' => ['orders.create' => false]]);
    $this->actingAs($staff)->get('/orders/create')->assertStatus(403);
});

test('staff can access order create page', function () {
    $staff = $this->staffUser();
    $this->actingAs($staff)->get('/orders/create')->assertStatus(200);
});