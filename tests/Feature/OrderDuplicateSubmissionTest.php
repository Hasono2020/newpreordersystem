<?php

use App\Models\ActivityLog;
use App\Models\CsAgent;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;

/*
 * Covers two things reported in production:
 *
 * 1. A staff member on an unstable connection ended up with two identical
 *    orders for the same customer/trip/items/total, created seconds apart.
 *    Fix: a per-page-load client_token, locked atomically in cache on the
 *    server, so a repeat submission of the same form is redirected to the
 *    order the first request created instead of making a second one.
 *
 * 2. That same duplicate-detection logic, when run as a standalone audit
 *    command (orders:find-duplicates), initially flagged legitimate Excel
 *    import batches as duplicates — a customer can genuinely have several
 *    separate real orders from one import, all created in the same second
 *    with identical items/total. Fix: orders now carry a source column, and
 *    the scanner only looks at source=manual orders.
 */

function makeOrderStorePayload(Product $product, CsAgent $agent, int $tripId, int $customerId, string $token): array
{
    return [
        'trip_id'     => $tripId,
        'customer_id' => $customerId,
        'cs_agent_id' => $agent->id,
        'items'       => [
            ['product_id' => $product->id, 'product_variant_id' => null, 'quantity' => 1, 'unit_price' => 100000],
        ],
        'client_token' => $token,
    ];
}

// ── HTTP-level: the real submit path staff actually hit ────────────────

test('submitting the same order form twice with the same client_token only creates one order', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $agent    = CsAgent::factory()->create();
    $product  = Product::create([
        'trip_id' => $trip->id, 'product_code' => 'DUP01',
        'price' => 100000, 'weight_gram' => 200, 'status' => 'active',
    ]);

    $payload = makeOrderStorePayload($product, $agent, $trip->id, $customer->id, 'token-same-abc');

    $first = $this->actingAs($admin)->post(route('orders.store'), $payload);
    $first->assertRedirect();
    expect(Order::count())->toBe(1);
    $firstOrderId = Order::first()->id;

    // Same page, same token — as if the button was double-clicked or the
    // browser retried after a flaky connection made the first click look
    // like it failed.
    $second = $this->actingAs($admin)->post(route('orders.store'), $payload);

    expect(Order::count())->toBe(1); // still just the one order
    $second->assertRedirect(route('orders.show', $firstOrderId));
    $second->assertSessionHas('success');

    expect(ActivityLog::where('action', 'order.duplicate_blocked')->count())->toBe(1);
});

test('two different client_tokens create two separate orders as normal', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $agent    = CsAgent::factory()->create();
    $product  = Product::create([
        'trip_id' => $trip->id, 'product_code' => 'DUP02',
        'price' => 100000, 'weight_gram' => 200, 'status' => 'active',
    ]);

    $this->actingAs($admin)->post(route('orders.store'), makeOrderStorePayload($product, $agent, $trip->id, $customer->id, 'token-a'));
    $this->actingAs($admin)->post(route('orders.store'), makeOrderStorePayload($product, $agent, $trip->id, $customer->id, 'token-b'));

    expect(Order::count())->toBe(2); // two genuinely separate orders, not blocked
});

test('a missing client_token (old cached page) still allows the order through', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $agent    = CsAgent::factory()->create();
    $product  = Product::create([
        'trip_id' => $trip->id, 'product_code' => 'DUP03',
        'price' => 100000, 'weight_gram' => 200, 'status' => 'active',
    ]);

    $payload = makeOrderStorePayload($product, $agent, $trip->id, $customer->id, '');
    unset($payload['client_token']);

    $response = $this->actingAs($admin)->post(route('orders.store'), $payload);
    $response->assertRedirect();
    expect(Order::count())->toBe(1);
});

test('a submission still being processed (lock held, no resolved order yet) is blocked without creating an order', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $agent    = CsAgent::factory()->create();
    $product  = Product::create([
        'trip_id' => $trip->id, 'product_code' => 'DUP04',
        'price' => 100000, 'weight_gram' => 200, 'status' => 'active',
    ]);

    $token = 'token-in-flight';
    Cache::put('order_submit_lock:' . $token, 'processing', now()->addMinutes(5));

    $response = $this->actingAs($admin)->post(
        route('orders.store'),
        makeOrderStorePayload($product, $agent, $trip->id, $customer->id, $token)
    );

    $response->assertRedirect(route('orders.index'));
    $response->assertSessionHas('error');
    expect(Order::count())->toBe(0);
});

// ── orders:find-duplicates command ──────────────────────────────────────

function makeScanOrder(int $tripId, int $customerId, int $createdBy, string $source, string $createdAt, Product $product): Order
{
    $order = Order::factory()->create([
        'trip_id'      => $tripId,
        'customer_id'  => $customerId,
        'created_by'   => $createdBy,
        'source'       => $source,
        'total_amount' => 100000,
        'created_at'   => $createdAt,
        'updated_at'   => $createdAt,
    ]);
    OrderItem::create([
        'order_id' => $order->id, 'product_id' => $product->id,
        'quantity' => 1, 'unit_price' => 100000, 'line_total' => 100000, 'status' => 'pending',
    ]);
    return $order;
}

test('orders:find-duplicates flags two manual orders with identical items seconds apart', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $product  = Product::create([
        'trip_id' => $trip->id, 'product_code' => 'SCAN01',
        'price' => 100000, 'weight_gram' => 200, 'status' => 'active',
    ]);

    $a = makeScanOrder($trip->id, $customer->id, $admin->id, 'manual', now()->toDateTimeString(), $product);
    $b = makeScanOrder($trip->id, $customer->id, $admin->id, 'manual', now()->addSeconds(2)->toDateTimeString(), $product);

    $exitCode = \Illuminate\Support\Facades\Artisan::call('orders:find-duplicates', ['--days' => 30]);
    $output   = \Illuminate\Support\Facades\Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('1 likely duplicate pair')
        ->and($output)->toContain($a->order_number)
        ->and($output)->toContain($b->order_number);
});

test('orders:find-duplicates ignores import-sourced orders even with identical items and timestamps', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $product  = Product::create([
        'trip_id' => $trip->id, 'product_code' => 'SCAN02',
        'price' => 100000, 'weight_gram' => 200, 'status' => 'active',
    ]);

    $now = now()->toDateTimeString();
    makeScanOrder($trip->id, $customer->id, $admin->id, 'import', $now, $product);
    makeScanOrder($trip->id, $customer->id, $admin->id, 'import', $now, $product);

    $exitCode = \Illuminate\Support\Facades\Artisan::call('orders:find-duplicates', ['--days' => 30]);
    $output   = \Illuminate\Support\Facades\Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('No likely duplicate manual orders found');
});

test('orders:find-duplicates --log only records Activity Log entries for pairs found in the last 24h', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $product  = Product::create([
        'trip_id' => $trip->id, 'product_code' => 'SCAN03',
        'price' => 100000, 'weight_gram' => 200, 'status' => 'active',
    ]);

    // Old pair — both orders created 3 days ago, 2s apart. Found by a wide
    // scan, but should NOT be (re-)logged since it's not fresh.
    $oldTime = now()->subDays(3);
    makeScanOrder($trip->id, $customer->id, $admin->id, 'manual', $oldTime->toDateTimeString(), $product);
    makeScanOrder($trip->id, $customer->id, $admin->id, 'manual', $oldTime->copy()->addSeconds(2)->toDateTimeString(), $product);

    $this->artisan('orders:find-duplicates', ['--days' => 30, '--log' => true])->assertExitCode(0);

    expect(ActivityLog::where('action', 'order.possible_duplicate')->count())->toBe(0);

    // Fresh pair — created just now, should get logged.
    $customer2 = $this->customer($admin);
    makeScanOrder($trip->id, $customer2->id, $admin->id, 'manual', now()->toDateTimeString(), $product);
    makeScanOrder($trip->id, $customer2->id, $admin->id, 'manual', now()->addSeconds(1)->toDateTimeString(), $product);

    $this->artisan('orders:find-duplicates', ['--days' => 30, '--log' => true])->assertExitCode(0);

    expect(ActivityLog::where('action', 'order.possible_duplicate')->count())->toBe(1);
});