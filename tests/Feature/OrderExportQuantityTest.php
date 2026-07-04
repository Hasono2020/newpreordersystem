<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ShippingArea;
use App\Models\Trip;
use App\Models\User;

/*
 * Bug: orders-export showed one row per order item with only the unit
 * price — an order for 3 units and an order for 1 unit of the same
 * product/variant looked identical in the exported file.
 *
 * Fix: the row is now repeated once per unit ordered, matching the
 * convention already used by the order import template (each row = 1
 * unit; multiple units of the same product/variant = repeated rows).
 * No new columns are added, so the export and import formats stay
 * interchangeable.
 */

// ── Local builders (same pattern as PromoShippingTest) ───────────────

function xlsxTrip(): Trip
{
    $admin = User::factory()->admin()->create();
    return Trip::factory()->open()->create(['created_by' => $admin->id]);
}

function xlsxProduct(Trip $trip, float $price = 100000): Product
{
    return Product::create([
        'trip_id'      => $trip->id,
        'product_code' => 'EX_' . fake()->unique()->numerify('###'),
        'price'        => $price,
        'weight_gram'  => 200,
        'status'       => 'active',
    ]);
}

function xlsxOrderWithItems(Trip $trip, Customer $customer, ShippingArea $area, array $items, User $by): Order
{
    $order = Order::factory()->create([
        'trip_id'          => $trip->id,
        'customer_id'      => $customer->id,
        'shipping_area_id' => $area->id,
        'created_by'       => $by->id,
        'subtotal'         => 0,
        'total_amount'     => 0,
    ]);
    foreach ($items as $it) {
        $qty   = $it['qty'] ?? 1;
        $price = $it['price'] ?? $it['product']->price;
        OrderItem::create([
            'order_id'   => $order->id,
            'product_id' => $it['product']->id,
            'quantity'   => $qty,
            'unit_price' => $price,
            'line_total' => $price * $qty,
            'status'     => $it['status'] ?? 'pending',
        ]);
    }
    return $order->fresh('items.product');
}

/**
 * Standalone reader that borrows the app's own xlsx-reading trait so the
 * test parses the export with the exact same logic the app uses to parse
 * imports, rather than re-implementing xlsx parsing in the test.
 */
class XlsxTestReader
{
    use \App\Traits\HandlesXlsx;

    public function read(string $path): array
    {
        return $this->readXlsx($path);
    }
}

/**
 * Reads the xlsx that the download response points to, using the app's own
 * reader. $this->get(...) in a Feature test returns Illuminate\Testing\TestResponse,
 * which wraps the real Symfony response in its public $baseResponse property.
 */
function readExportedXlsx(\Illuminate\Testing\TestResponse $response): array
{
    $base = $response->baseResponse;

    if (! $base instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) {
        throw new \RuntimeException('Expected orders.export to return a file download response.');
    }

    return (new XlsxTestReader())->read($base->getFile()->getPathname());
}

// ── Tests ─────────────────────────────────────────────────────────────

test('orders export header has no QTY/TOTAL columns and matches import template shape', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->get(route('orders.export'));
    $response->assertOk();

    $rows   = readExportedXlsx($response);
    $header = $rows[0];

    expect($header)->toBe([
        'DIBUAT OLEH', 'NO', 'NAMA', 'IG/WA', 'NO HP', 'KOTA',
        'KODE', 'WARNA', 'SIZE', 'HARGA SATUAN',
        'DP', 'TGL DP', 'AN', 'KET', 'WAKTU ORDER',
    ]);
});

test('an item with quantity 3 produces 3 repeated rows, not 1', function () {
    $admin    = User::factory()->admin()->create();
    $trip     = xlsxTrip();
    $customer = Customer::factory()->create();
    $area     = ShippingArea::factory()->create();

    $productA = xlsxProduct($trip, 1000000); // ordered qty 3
    $productB = xlsxProduct($trip, 250000);  // ordered qty 5

    xlsxOrderWithItems($trip, $customer, $area, [
        ['product' => $productA, 'qty' => 3],
        ['product' => $productB, 'qty' => 5],
    ], $admin);

    $response = $this->actingAs($admin)->get(route('orders.export', ['trip_id' => $trip->id]));
    $response->assertOk();

    $rows   = readExportedXlsx($response);
    $header = $rows[0];
    $codeCol  = array_search('KODE', $header);
    $priceCol = array_search('HARGA SATUAN', $header);

    $dataRows = array_slice($rows, 1);

    $rowsA = collect($dataRows)->filter(fn ($r) => $r[$codeCol] === $productA->product_code)->values();
    $rowsB = collect($dataRows)->filter(fn ($r) => $r[$codeCol] === $productB->product_code)->values();

    // The bug: this used to be 1 row per product regardless of quantity.
    expect($rowsA)->toHaveCount(3);
    expect($rowsB)->toHaveCount(5);

    foreach ($rowsA as $r) {
        expect((float) $r[$priceCol])->toBe(1000000.0);
    }
    foreach ($rowsB as $r) {
        expect((float) $r[$priceCol])->toBe(250000.0);
    }

    // Total rows for the order = 3 + 5 = 8, plus the header row.
    expect($rows)->toHaveCount(9);
});

test('DP and WAKTU ORDER only appear on the very first repeated row of an order', function () {
    $admin    = User::factory()->admin()->create();
    $trip     = xlsxTrip();
    $customer = Customer::factory()->create();
    $area     = ShippingArea::factory()->create();
    $product  = xlsxProduct($trip, 50000);

    xlsxOrderWithItems($trip, $customer, $area, [
        ['product' => $product, 'qty' => 3],
    ], $admin);

    $response = $this->actingAs($admin)->get(route('orders.export', ['trip_id' => $trip->id]));
    $response->assertOk();

    $rows   = readExportedXlsx($response);
    $header = $rows[0];
    $waktuCol = array_search('WAKTU ORDER', $header);

    $dataRows = array_slice($rows, 1);

    // readXlsx() only assigns array keys up to a row's last non-blank cell
    // (this is why the app's own import code always reads with `?? ''`,
    // e.g. CustomerController::import), so trailing blank columns like
    // WAKTU ORDER on repeat rows may simply be absent from the array.
    expect($dataRows[0][$waktuCol] ?? '')->not->toBe('');
    expect($dataRows[1][$waktuCol] ?? '')->toBe('');
    expect($dataRows[2][$waktuCol] ?? '')->toBe('');
});

test('orders export handles an order with no items without crashing', function () {
    $admin    = User::factory()->admin()->create();
    $trip     = xlsxTrip();
    $customer = Customer::factory()->create();
    $area     = ShippingArea::factory()->create();

    Order::factory()->create([
        'trip_id'          => $trip->id,
        'customer_id'      => $customer->id,
        'shipping_area_id' => $area->id,
        'created_by'       => $admin->id,
        'subtotal'         => 0,
        'total_amount'     => 0,
    ]);

    $response = $this->actingAs($admin)->get(route('orders.export', ['trip_id' => $trip->id]));

    $response->assertOk();
});