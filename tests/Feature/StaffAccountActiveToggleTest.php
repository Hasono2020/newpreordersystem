<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/*
 * Bug: unchecking "Active account" and saving didn't actually deactivate the
 * user — HTML checkboxes send nothing at all when unchecked, and the
 * controller read the missing key with a default of `true`, so every save
 * with the toggle off silently re-enabled the account instead of disabling
 * it. Fixed by defaulting to `false` when the key is absent.
 */

function staffUpdatePayload(User $staff, array $overrides = []): array
{
    return array_merge([
        'name'  => $staff->name,
        'email' => $staff->email,
        'role'  => $staff->role,
        'phone' => null,
        'notes' => null,
        // 'is_active' intentionally absent below unless overridden —
        // this is what an unchecked checkbox actually sends: nothing.
    ], $overrides);
}

test('unchecking Active account (an absent is_active key) actually deactivates the account', function () {
    $admin = $this->adminUser();
    $staff = User::factory()->create(['role' => 'staff', 'is_active' => true]);

    $response = $this->actingAs($admin)->put(route('staff.update', $staff), staffUpdatePayload($staff));

    $response->assertRedirect();
    expect($staff->fresh()->is_active)->toBeFalse();
});

test('checking Active account keeps the account active', function () {
    $admin = $this->adminUser();
    $staff = User::factory()->create(['role' => 'staff', 'is_active' => true]);

    $response = $this->actingAs($admin)->put(
        route('staff.update', $staff),
        staffUpdatePayload($staff, ['is_active' => '1'])
    );

    $response->assertRedirect();
    expect($staff->fresh()->is_active)->toBeTrue();
});

test('re-activating a previously deactivated account works the same way in reverse', function () {
    $admin = $this->adminUser();
    $staff = User::factory()->create(['role' => 'staff', 'is_active' => false]);

    $this->actingAs($admin)->put(
        route('staff.update', $staff),
        staffUpdatePayload($staff, ['is_active' => '1'])
    );

    expect($staff->fresh()->is_active)->toBeTrue();
});

test('a deactivated account cannot log in even with the correct password', function () {
    $staff = User::factory()->create([
        'role' => 'staff', 'is_active' => false, 'password' => Hash::make('correct-password'),
    ]);

    $response = $this->post(route('login'), [
        'email' => $staff->email, 'password' => 'correct-password',
    ]);

    $response->assertSessionHasErrors('email');
    expect(Auth::check())->toBeFalse();
});
