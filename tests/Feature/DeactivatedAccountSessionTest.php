<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/*
 * Reported scenario: an admin deactivates cs abc's account, but cs abc is
 * still logged in from before. Their existing session kept working — and
 * worse, it started seeing MORE data than before (every staff member's
 * orders, not just their own), because own_data is an inverted permission
 * flag and hasPermission() collapses everything to false once is_active is
 * false. Two fixes: User::isOwnDataOnly() fails safe when inactive, and a
 * new middleware ends an already-open session on its very next request.
 */

test('an already-logged-in session for a newly-deactivated account is logged out on its next request', function () {
    $staff = User::factory()->create(['role' => 'staff', 'is_active' => true]);

    $this->actingAs($staff);
    $this->get(route('orders.index'))->assertOk(); // session genuinely works beforehand

    // Admin deactivates the account elsewhere; this session stays open.
    $staff->update(['is_active' => false]);

    $response = $this->get(route('orders.index'));
    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors('email');
    expect(Auth::check())->toBeFalse();
});

test('a still-active session is completely unaffected by the new middleware', function () {
    $staff = User::factory()->create(['role' => 'staff', 'is_active' => true]);

    $this->actingAs($staff);
    $this->get(route('orders.index'))->assertOk();
    expect(Auth::check())->toBeTrue();
});

test('isOwnDataOnly fails safe (restrictive) for a deactivated staff account instead of losing the restriction', function () {
    $staff = User::factory()->create(['role' => 'staff', 'is_active' => false, 'permissions' => null]);
    expect($staff->isOwnDataOnly())->toBeTrue();
});

test('isOwnDataOnly still reflects the normal staff-role default when the account is active', function () {
    $staff = User::factory()->create(['role' => 'staff', 'is_active' => true, 'permissions' => null]);
    expect($staff->isOwnDataOnly())->toBeTrue(); // staff role defaults own_data=true
});

test('isOwnDataOnly is false for an active admin, but still fails safe to true if that admin account is deactivated', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    expect($admin->isOwnDataOnly())->toBeFalse();

    $admin->update(['is_active' => false]);
    expect($admin->isOwnDataOnly())->toBeTrue(); // still fails safe — an inactive admin is not exempt
});
