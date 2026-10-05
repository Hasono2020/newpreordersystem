<?php

use App\Models\CsAgent;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;

/*
 * Orders export:
 *  - NO URUT: each order's position (1, 2, 3 …) in the FIFO list of ALL orders
 *    in its trip, repeated on every row of that order.
 *  - Filtering is by CS AGENT (the IG/WA column, e.g. "CS1 Endang") — several at
 *    once — not by the staff account that typed the order in.
 *  - Filtering never renumbers: if CS 1 handled orders 1, 3, 5 and CS 2 handled
 *    2, 4, 6, an export of CS 2 alone shows 2, 4, 6.
 *
 * These read the real downloaded file (with the app's own xlsx reader) rather
 * than inferring from the database, because the numbers in the file are the
 * thing being promised.
 */

class FifoExportReader
{
    use \App\Traits\HandlesXlsx;

    public function read(string $path): array
    {
        return $this->readXlsx($path);
    }
}

function fifoExportRows(\Illuminate\Testing\TestResponse $response): array
{
    $base = $response->baseResponse;
    if (! $base instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) {
        throw new \RuntimeException('Expected orders.export to return a file download response.');
    }
    return (new FifoExportReader())->read($base->getFile()->getPathname());
}

/** order_number => NO URUT, in the order the rows appear in the file. */
function fifoMap(array $rows): array
{
    $header = $rows[0];
    $orderCol  = array_search('NO ORDER', $header);
    $numberCol = array_search('NO URUT', $header);

    $map = [];
    foreach (array_slice($rows, 1) as $r) {
        $map[(string) $r[$orderCol]] = (int) $r[$numberCol];
    }
    return $map;
}

function fifoOrder($trip, $customer, $createdBy, ?CsAgent $agent, $orderedAt, int $qty = 1): Order
{
    $product = Product::create([
        'trip_id' => $trip->id, 'product_code' => 'FF' . fake()->unique()->numerify('######'),
        'price' => 100000, 'weight_gram' => 100, 'status' => 'active',
    ]);
    $order = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $createdBy->id,
        'cs_agent_id' => $agent?->id, 'ordered_at' => $orderedAt, 'order_number' => null,
        'shipping_area_id' => null,
    ]);
    OrderItem::create([
        'order_id' => $order->id, 'product_id' => $product->id, 'quantity' => $qty,
        'unit_price' => 100000, 'line_total' => 100000 * $qty, 'status' => 'pending',
    ]);
    return $order;
}

/** Six orders, oldest first, alternating CS 1 / CS 2 — CS 1 gets 1,3,5 and CS 2 gets 2,4,6. */
function alternatingSix($test, $admin): array
{
    $trip     = $test->openTrip();
    $customer = $test->customer($admin);
    $cs1 = CsAgent::factory()->create(['name' => 'CS1 Endang']);
    $cs2 = CsAgent::factory()->create(['name' => 'CS2 Rita']);

    $orders = [];
    for ($i = 1; $i <= 6; $i++) {
        $orders[$i] = fifoOrder($trip, $customer, $admin, $i % 2 === 1 ? $cs1 : $cs2, now()->subMinutes(70 - $i * 10));
    }
    return [$trip, $cs1, $cs2, $orders];
}

test('NO URUT is the first column, and the columns after it keep their usual order', function () {
    $admin = $this->adminUser();

    $rows   = fifoExportRows($this->actingAs($admin)->get(route('orders.export')));
    $header = $rows[0];

    expect($header[0])->toBe('NO URUT');
    // Everything after it is the import template's layout, in the same order. (The
    // import sets the leading NO URUT column aside — see OrderImportAcceptsExportTest.)
    expect(array_slice($header, 1, 15))->toBe([
        'DIBUAT OLEH', 'NO ORDER', 'NAMA', 'IG/WA', 'NO HP', 'KOTA',
        'KODE', 'WARNA', 'SIZE', 'HARGA SATUAN',
        'DP', 'TGL DP', 'AN', 'KET', 'WAKTU ORDER',
    ]);
});

test('orders are numbered 1, 2, 3 … in FIFO order', function () {
    $admin = $this->adminUser();
    [$trip, $cs1, $cs2, $orders] = alternatingSix($this, $admin);

    $map = fifoMap(fifoExportRows($this->actingAs($admin)->get(route('orders.export', ['trip_id' => $trip->id]))));

    expect(array_values($map))->toBe([1, 2, 3, 4, 5, 6]);
    foreach ($orders as $i => $order) {
        expect($map[$order->order_number])->toBe($i);
    }
});

test('filtering to CS 2 shows 2, 4, 6 — the numbers do not restart', function () {
    $admin = $this->adminUser();
    [$trip, $cs1, $cs2, $orders] = alternatingSix($this, $admin);

    $map = fifoMap(fifoExportRows($this->actingAs($admin)->get(route('orders.export', [
        'trip_id' => $trip->id, 'cs_agent_ids' => [$cs2->id],
    ]))));

    expect(array_values($map))->toBe([2, 4, 6]);
    expect(array_keys($map))->toBe([$orders[2]->order_number, $orders[4]->order_number, $orders[6]->order_number]);
});

test('filtering to CS 1 shows 1, 3, 5', function () {
    $admin = $this->adminUser();
    [$trip, $cs1, $cs2, $orders] = alternatingSix($this, $admin);

    $map = fifoMap(fifoExportRows($this->actingAs($admin)->get(route('orders.export', [
        'trip_id' => $trip->id, 'cs_agent_ids' => [$cs1->id],
    ]))));

    expect(array_values($map))->toBe([1, 3, 5]);
});

test('several CS agents can be selected at once', function () {
    $admin = $this->adminUser();
    [$trip, $cs1, $cs2, $orders] = alternatingSix($this, $admin);
    $cs3 = CsAgent::factory()->create(['name' => 'CS3 Chia']);
    $customer = $this->customer($admin);
    fifoOrder($trip, $customer, $admin, $cs3, now()); // a seventh order, newest, CS 3

    $map = fifoMap(fifoExportRows($this->actingAs($admin)->get(route('orders.export', [
        'trip_id' => $trip->id, 'cs_agent_ids' => [$cs1->id, $cs3->id],
    ]))));

    expect(array_values($map))->toBe([1, 3, 5, 7]); // CS 2's 2, 4, 6 are simply absent
});

test('the filter is by CS agent, not by the staff account that created the order', function () {
    // Every order here was typed in by the same admin account, yet the filter
    // still separates them — because it follows the CS agent on the order.
    $admin = $this->adminUser();
    [$trip, $cs1, $cs2, $orders] = alternatingSix($this, $admin);

    foreach ($orders as $o) expect($o->created_by)->toBe($admin->id);

    $map = fifoMap(fifoExportRows($this->actingAs($admin)->get(route('orders.export', [
        'trip_id' => $trip->id, 'cs_agent_ids' => [$cs2->id],
    ]))));
    expect(count($map))->toBe(3);
});

test('numbering follows the order date & time (FIFO), not the order they were typed in', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $cs       = CsAgent::factory()->create();

    $typedFirst  = fifoOrder($trip, $customer, $admin, $cs, now());            // entered first, but ordered LATER
    $typedSecond = fifoOrder($trip, $customer, $admin, $cs, now()->subDay());  // entered second, but ordered EARLIER (a missed order added afterwards)

    $map = fifoMap(fifoExportRows($this->actingAs($admin)->get(route('orders.export', ['trip_id' => $trip->id]))));

    expect($map[$typedSecond->order_number])->toBe(1);
    expect($map[$typedFirst->order_number])->toBe(2);
    expect(array_keys($map))->toBe([$typedSecond->order_number, $typedFirst->order_number]); // rows are in FIFO order too
});

test('the number is repeated on every row of an order that has several units', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $cs       = CsAgent::factory()->create();

    fifoOrder($trip, $customer, $admin, $cs, now()->subMinutes(10), 1);
    $multi = fifoOrder($trip, $customer, $admin, $cs, now(), 3); // 3 units = 3 rows

    $rows      = fifoExportRows($this->actingAs($admin)->get(route('orders.export', ['trip_id' => $trip->id])));
    $orderCol  = array_search('NO ORDER', $rows[0]);
    $numberCol = array_search('NO URUT', $rows[0]);

    $multiRows = array_values(array_filter(array_slice($rows, 1), fn ($r) => $r[$orderCol] === $multi->order_number));
    expect($multiRows)->toHaveCount(3);
    foreach ($multiRows as $r) expect((int) $r[$numberCol])->toBe(2);
});

test('numbering restarts at 1 for each trip when several trips are exported together', function () {
    $admin    = $this->adminUser();
    $customer = $this->customer($admin);
    $cs       = CsAgent::factory()->create();
    $tripA    = $this->openTrip();
    $tripB    = $this->openTrip();

    $a1 = fifoOrder($tripA, $customer, $admin, $cs, now()->subHours(4));
    $b1 = fifoOrder($tripB, $customer, $admin, $cs, now()->subHours(3));
    $a2 = fifoOrder($tripA, $customer, $admin, $cs, now()->subHours(2));
    $b2 = fifoOrder($tripB, $customer, $admin, $cs, now()->subHours(1));

    $map = fifoMap(fifoExportRows($this->actingAs($admin)->get(route('orders.export'))));

    expect($map[$a1->order_number])->toBe(1);
    expect($map[$a2->order_number])->toBe(2);
    expect($map[$b1->order_number])->toBe(1);
    expect($map[$b2->order_number])->toBe(2);
});

test('"No CS agent assigned" picks out orders that have none', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $cs       = CsAgent::factory()->create();

    fifoOrder($trip, $customer, $admin, $cs, now()->subMinutes(30));          // 1 — has an agent
    $noAgent = fifoOrder($trip, $customer, $admin, null, now()->subMinutes(20)); // 2 — none
    fifoOrder($trip, $customer, $admin, $cs, now()->subMinutes(10));          // 3 — has an agent

    $map = fifoMap(fifoExportRows($this->actingAs($admin)->get(route('orders.export', [
        'trip_id' => $trip->id, 'cs_agent_ids' => ['none'],
    ]))));
    expect($map)->toBe([$noAgent->order_number => 2]);
});

test('a staff member who can only see their own orders still gets the real FIFO numbers', function () {
    // The number is an identifier, so it must read the same in their export as
    // in an admin's — their orders are 2 and 4 in the trip, not 1 and 2.
    $admin    = $this->adminUser();
    $staff    = $this->staffUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $cs       = CsAgent::factory()->create();

    fifoOrder($trip, $customer, $admin, $cs, now()->subMinutes(40));            // 1 — admin's
    $mine1 = fifoOrder($trip, $customer, $staff, $cs, now()->subMinutes(30));   // 2 — staff's
    fifoOrder($trip, $customer, $admin, $cs, now()->subMinutes(20));            // 3 — admin's
    $mine2 = fifoOrder($trip, $customer, $staff, $cs, now()->subMinutes(10));   // 4 — staff's

    $map = fifoMap(fifoExportRows($this->actingAs($staff)->get(route('orders.export', ['trip_id' => $trip->id]))));

    expect($map)->toBe([$mine1->order_number => 2, $mine2->order_number => 4]);
});

test('the stored order numbers are untouched by filtering', function () {
    $admin = $this->adminUser();
    [$trip, $cs1, $cs2, $orders] = alternatingSix($this, $admin);
    $before = $orders[4]->order_number;

    $this->actingAs($admin)->get(route('orders.export', ['trip_id' => $trip->id, 'cs_agent_ids' => [$cs2->id]]))->assertOk();

    expect($orders[4]->fresh()->order_number)->toBe($before);
});
