<?php

use App\Models\ActivityLog;

// ── Access control (the security-critical part) ──────────────────────

test('admin can view the activity log page', function () {
    $admin = $this->adminUser();
    $this->actingAs($admin)->get('/activity-logs')->assertStatus(200);
});

test('staff cannot view the activity log page', function () {
    $staff = $this->staffUser();
    $this->actingAs($staff)->get('/activity-logs')->assertStatus(403);
});

test('finance cannot view the activity log page', function () {
    $finance = $this->financeUser();
    $this->actingAs($finance)->get('/activity-logs')->assertStatus(403);
});

test('guest is redirected away from the activity log page', function () {
    // not logged in → auth middleware should redirect (not 200)
    $this->get('/activity-logs')->assertRedirect();
});

// ── The record() helper ──────────────────────────────────────────────

test('record() writes an activity log entry with the acting user', function () {
    $admin = $this->adminUser();
    $this->actingAs($admin);

    ActivityLog::record('payment.voided', 'Voided Rp 500.000 on ORD-TEST', 'order', 123);

    $log = ActivityLog::first();
    expect($log)->not->toBeNull()
        ->and($log->action)->toBe('payment.voided')
        ->and($log->description)->toBe('Voided Rp 500.000 on ORD-TEST')
        ->and($log->subject_type)->toBe('order')
        ->and($log->subject_id)->toBe(123)
        ->and($log->user_id)->toBe($admin->id)
        ->and($log->user_name)->toBe($admin->name);
});

test('record() stores before/after changes as an array', function () {
    $this->actingAs($this->adminUser());

    ActivityLog::record('order.updated', 'Edited ORD-TEST', 'order', 1, [
        'total_amount' => ['old' => 1000000, 'new' => 1150000],
    ]);

    $log = ActivityLog::first();
    expect($log->changes)->toBeArray()
        ->and($log->changes['total_amount']['old'])->toBe(1000000)
        ->and($log->changes['total_amount']['new'])->toBe(1150000);
});

test('record() never throws even with a bad subject and no user', function () {
    // No acting user, weird input — should swallow errors, not blow up.
    ActivityLog::record('order.deleted', 'something happened');
    $log = ActivityLog::first();
    expect($log->user_name)->toBe('System'); // falls back to 'System' when no auth user
});

// ── Filtering ────────────────────────────────────────────────────────

test('activity log can be filtered by action type', function () {
    $admin = $this->adminUser();
    $this->actingAs($admin);

    ActivityLog::record('payment.recorded', 'Recorded a payment', 'order', 1);
    ActivityLog::record('order.deleted', 'Deleted an order', 'order', 2);

    $this->actingAs($admin)
        ->get('/activity-logs?action=order.deleted')
        ->assertStatus(200)
        ->assertSee('Deleted an order')
        ->assertDontSee('Recorded a payment');
});