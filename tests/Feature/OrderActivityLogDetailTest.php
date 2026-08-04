<?php

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Product;

/*
 * Reported problem: every order.updated entry in Activity Log read as the
 * identical generic "Edited order ORD-xxx (Customer)" — no way to tell what
 * actually changed without clicking "view changes" every single time. Worse,
 * adding or removing a line item from an order didn't log anything at all,
 * since items live in a separate table the field-diff logic never looked at.
 *
 * Fix: the description itself now names what changed, and addItem/removeItem
 * now log too, reusing the same order.updated action per the request.
 */

test('editing order fields names which fields changed in the description', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $order    = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id,
        'shipping_area_id' => $customer->default_shipping_area_id,
    ]);

    $this->actingAs($admin)->put(route('orders.update', $order), [
        'notes' => 'New note text',
    ]);

    $log = ActivityLog::where('action', 'order.updated')->where('subject_id', $order->id)->first();
    expect($log)->not->toBeNull();
    expect($log->description)->toContain('notes')
        ->and($log->description)->not->toContain('shipping area'); // only the field that actually changed
});

test('adding a brand new item to an order logs the specific product, not a generic message', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $order    = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id]);
    $product  = Product::create([
        'trip_id' => $trip->id, 'product_code' => 'LOGADD01',
        'price' => 75000, 'weight_gram' => 150, 'status' => 'active',
    ]);

    $this->actingAs($admin)->post(route('orders.items.add', $order), [
        'product_id' => $product->id, 'product_variant_id' => null,
        'quantity' => 3, 'unit_price' => 75000,
    ]);

    $log = ActivityLog::where('action', 'order.updated')->where('subject_id', $order->id)->latest()->first();
    expect($log->description)->toContain('added item')
        ->and($log->description)->toContain('LOGADD01');
    expect($log->changes['item_added']['new'])->toContain('LOGADD01')
        ->and($log->changes['item_added']['new'])->toContain('x3');
});

test('adding the same product again merges quantity and logs an increase, not a second "added item"', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $order    = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id]);
    $product  = Product::create([
        'trip_id' => $trip->id, 'product_code' => 'LOGADD02',
        'price' => 20000, 'weight_gram' => 100, 'status' => 'active',
    ]);

    $payload = ['product_id' => $product->id, 'product_variant_id' => null, 'quantity' => 2, 'unit_price' => 20000];
    $this->actingAs($admin)->post(route('orders.items.add', $order), $payload);
    $this->actingAs($admin)->post(route('orders.items.add', $order), $payload);

    $log = ActivityLog::where('action', 'order.updated')->where('subject_id', $order->id)->latest()->first();
    expect($log->description)->toContain('increased quantity')
        ->and($log->description)->not->toContain('added item');
    expect($log->changes['item_quantity']['old'])->toContain('x2')
        ->and($log->changes['item_quantity']['new'])->toContain('x4');
});

test('removing an item logs which specific item was removed, captured before the delete', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $order    = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id]);
    $product  = Product::create([
        'trip_id' => $trip->id, 'product_code' => 'LOGDEL01',
        'price' => 40000, 'weight_gram' => 120, 'status' => 'active',
    ]);

    $this->actingAs($admin)->post(route('orders.items.add', $order), [
        'product_id' => $product->id, 'product_variant_id' => null, 'quantity' => 5, 'unit_price' => 40000,
    ]);
    $item = $order->items()->first();

    $this->actingAs($admin)->delete(route('orders.items.remove', [$order, $item]));

    $log = ActivityLog::where('action', 'order.updated')->where('subject_id', $order->id)->latest()->first();
    expect($log->description)->toContain('removed item')
        ->and($log->description)->toContain('LOGDEL01');
    expect($log->changes['item_removed']['old'])->toContain('LOGDEL01')
        ->and($log->changes['item_removed']['old'])->toContain('x5')
        ->and($log->changes['item_removed']['new'])->toBe('— (removed)');
});
