<?php

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Order;

function makeRecapOrder(int $tripId, int $customerId, int $createdBy, float $total): Order
{
    return Order::factory()->create([
        'trip_id' => $tripId, 'customer_id' => $customerId,
        'created_by' => $createdBy, 'total_amount' => $total,
    ]);
}

test('the correct password downloads the sales recap as an xlsx file', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    makeRecapOrder($trip->id, $customer->id, $admin->id, 150000);

    $response = $this->actingAs($admin)->post(route('reports.export.sales-recap'), [
        'trip_id' => $trip->id, 'password' => 'password', // UserFactory's default plaintext
    ]);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))
        ->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    expect($response->headers->get('Content-Disposition'))->toContain('sales_recap');

    expect(ActivityLog::where('action', 'report.sales_recap_exported')->count())->toBe(1);
});

test('an incorrect password blocks the export and creates no Activity Log entry', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    makeRecapOrder($trip->id, $customer->id, $admin->id, 150000);

    $response = $this->actingAs($admin)->post(route('reports.export.sales-recap'), [
        'trip_id' => $trip->id, 'password' => 'wrong-password',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('error');
    expect(ActivityLog::where('action', 'report.sales_recap_exported')->count())->toBe(0);
});

test('a fully-cancelled order (total_amount 0) is excluded from the recap', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    makeRecapOrder($trip->id, $customer->id, $admin->id, 150000); // counts
    makeRecapOrder($trip->id, $customer->id, $admin->id, 0);      // excluded

    $this->actingAs($admin)->post(route('reports.export.sales-recap'), [
        'trip_id' => $trip->id, 'password' => 'password',
    ]);

    $log = ActivityLog::where('action', 'report.sales_recap_exported')->first();
    expect($log->description)->toContain('1 order(s)')
        ->and($log->description)->toContain('Rp 150.000');
});

test('own_data-only staff only get their own orders in the recap, not every staff member\'s', function () {
    $admin = $this->adminUser();
    $staffA = $this->staffUser(['permissions' => ['reports.view' => true]]);
    $staffB = $this->staffUser(['permissions' => ['reports.view' => true]]);
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);

    makeRecapOrder($trip->id, $customer->id, $staffA->id, 100000); // staffA's own
    makeRecapOrder($trip->id, $customer->id, $staffB->id, 999000); // someone else's

    $this->actingAs($staffA)->post(route('reports.export.sales-recap'), [
        'trip_id' => $trip->id, 'password' => 'password',
    ]);

    $log = ActivityLog::where('action', 'report.sales_recap_exported')->first();
    expect($log->description)->toContain('1 order(s)')
        ->and($log->description)->toContain('Rp 100.000')
        ->and($log->description)->not->toContain('999.000');
});

test('admin sees every staff member\'s orders in the recap, not just their own', function () {
    $admin  = $this->adminUser();
    $staffA = $this->staffUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);

    makeRecapOrder($trip->id, $customer->id, $admin->id, 100000);
    makeRecapOrder($trip->id, $customer->id, $staffA->id, 200000);

    $this->actingAs($admin)->post(route('reports.export.sales-recap'), [
        'trip_id' => $trip->id, 'password' => 'password',
    ]);

    $log = ActivityLog::where('action', 'report.sales_recap_exported')->first();
    expect($log->description)->toContain('2 order(s)')
        ->and($log->description)->toContain('Rp 300.000');
});
