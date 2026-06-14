<?php
use App\Models\Order;
use App\Models\User;

test('admin sees all orders', function () {
    $staffA  = $this->staffUser();
    $staffB  = $this->staffUser();
    $admin   = $this->adminUser();
    $trip    = $this->openTrip();
    $area    = $this->shippingArea();
    $custA   = $this->customer($staffA);
    $custB   = $this->customer($staffB);

    Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $custA->id, 'created_by' => $staffA->id, 'shipping_area_id' => $area->id]);
    Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $custB->id, 'created_by' => $staffB->id, 'shipping_area_id' => $area->id]);

    $this->actingAs($admin)->get('/orders')
         ->assertStatus(200)
         ->assertSee($custA->name)
         ->assertSee($custB->name);
});

test('own_data staff only sees their own orders', function () {
    $staffA = $this->ownDataStaff();
    $staffB = $this->ownDataStaff();
    $trip   = $this->openTrip();
    $area   = $this->shippingArea();
    $custA  = $this->customer($staffA);
    $custB  = $this->customer($staffB);

    Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $custA->id, 'created_by' => $staffA->id, 'shipping_area_id' => $area->id]);
    Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $custB->id, 'created_by' => $staffB->id, 'shipping_area_id' => $area->id]);

    $response = $this->actingAs($staffA)->get('/orders');
    $response->assertStatus(200)
             ->assertSee($custA->name)
             ->assertDontSee($custB->name);
});

test('all staff see all customers regardless of own_data', function () {
    $staffA = $this->ownDataStaff();
    $staffB = $this->ownDataStaff();
    $custA  = $this->customer($staffA);
    $custB  = $this->customer($staffB);

    // staffA should see custB even though staffB created them
    $this->actingAs($staffA)->get('/customers')
         ->assertStatus(200)
         ->assertSee($custA->name)
         ->assertSee($custB->name);
});
