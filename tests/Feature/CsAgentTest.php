<?php

use App\Models\CsAgent;
use App\Models\Order;

test('cs agent can be created and linked to an order', function () {
    $cs    = CsAgent::factory()->create(['name' => 'Rina']);
    $trip  = $this->openTrip();
    $area  = $this->shippingArea();
    $staff = $this->staffUser();
    $cust  = $this->customer($staff);

    $order = Order::factory()->create([
        'trip_id'          => $trip->id,
        'customer_id'      => $cust->id,
        'created_by'       => $staff->id,
        'shipping_area_id' => $area->id,
        'cs_agent_id'      => $cs->id,
    ]);

    expect($order->csAgent)->not->toBeNull();
    expect($order->csAgent->name)->toBe('Rina');
});

test('deleting a cs agent nullifies the order link but keeps the order', function () {
    $cs    = CsAgent::factory()->create();
    $trip  = $this->openTrip();
    $area  = $this->shippingArea();
    $staff = $this->staffUser();
    $cust  = $this->customer($staff);

    $order = Order::factory()->create([
        'trip_id'          => $trip->id,
        'customer_id'      => $cust->id,
        'created_by'       => $staff->id,
        'shipping_area_id' => $area->id,
        'cs_agent_id'      => $cs->id,
    ]);

    $this->actingAs($this->adminUser())
         ->delete(route('cs-agents.destroy', $cs))
         ->assertRedirect();

    $order->refresh();
    expect(Order::find($order->id))->not->toBeNull();
    expect($order->cs_agent_id)->toBeNull();
});

test('admin can create a cs agent via the form', function () {
    $this->actingAs($this->adminUser())
         ->post(route('cs-agents.store'), [
             'name'      => 'Dewi',
             'handle'    => '@dewi_cs',
             'is_active' => 1,
         ])
         ->assertRedirect();

    expect(CsAgent::where('name', 'Dewi')->exists())->toBeTrue();
});

test('cs agent name is required', function () {
    $this->actingAs($this->adminUser())
         ->post(route('cs-agents.store'), ['name' => ''])
         ->assertSessionHasErrors('name');
});