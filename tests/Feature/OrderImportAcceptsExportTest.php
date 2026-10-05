<?php

use App\Models\CsAgent;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\OrderImportService;

/*
 * "Export orders" now puts NO URUT in column 1, which shifts every other column
 * one place to the right of the plain import template. The import reads columns
 * BY POSITION (name = 3rd cell, CS agent = 4th, product code = 7th …), so
 * without help, re-importing an exported file would read every field one cell to
 * the right — a customer's name taken from the order number, and so on.
 *
 * The import now recognises a leading NO URUT header and sets that column aside,
 * so both layouts read identically.
 */

class ImportFixtureWriter
{
    use \App\Traits\HandlesXlsx;

    /** Write rows to a real .xlsx on disk and return its path. */
    public function write(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'imp_') . '.xlsx';
        file_put_contents($path, $this->buildXlsx($rows));
        return $path;
    }
}

function importFixtureFile(array $rows): string
{
    return (new ImportFixtureWriter())->write($rows);
}

const PLAIN_IMPORT_HEADER = ['DIBUAT OLEH', 'NO', 'NAMA', 'IG/WA', 'NO HP', 'KOTA', 'KODE', 'WARNA', 'SIZE', 'HARGA SATUAN', 'DP', 'TGL DP', 'AN', 'KET'];

test('a file exported from the Orders page can be imported straight back', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $agent    = CsAgent::factory()->create(['name' => 'CS1 Endang']);
    $product  = Product::create(['trip_id' => $trip->id, 'product_code' => 'RT100', 'price' => 100000, 'weight_gram' => 100, 'status' => 'active']);

    $order = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
        'cs_agent_id' => $agent->id, 'shipping_area_id' => null, 'order_number' => null,
    ]);
    OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 2, 'unit_price' => 100000, 'line_total' => 200000, 'status' => 'pending']);

    // The real file the Orders page would hand to the user.
    $response = $this->actingAs($admin)->get(route('orders.export', ['trip_id' => $trip->id]));
    $path = $response->baseResponse->getFile()->getPathname();

    $result = app(OrderImportService::class)->readAndValidate($path, $trip);

    expect($result['errors'])->toBe([]);          // nothing mis-read, so nothing flagged
    expect($result['rows'])->toHaveCount(2);      // 2 units = 2 rows
    foreach ($result['rows'] as $row) {
        expect($row[2])->toBe($customer->name);   // NAMA — not the order number or the creator
        expect($row[3])->toBe('CS1 Endang');      // IG/WA
        expect($row[6])->toBe('RT100');           // KODE
    }
});

test('the plain import template layout (no NO URUT) still reads exactly as before', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    CsAgent::factory()->create(['name' => 'CS1 Endang']);
    Product::create(['trip_id' => $trip->id, 'product_code' => 'PL200', 'price' => 50000, 'weight_gram' => 100, 'status' => 'active']);

    $path = importFixtureFile([
        PLAIN_IMPORT_HEADER,
        ['Admin', '1', 'Budi Santoso', 'CS1 Endang', '0812345', 'BATAM', 'PL200', '', '', '50000', '', '', '', ''],
    ]);

    $result = app(OrderImportService::class)->readAndValidate($path, $trip);

    expect($result['errors'])->toBe([]);
    expect($result['rows'])->toHaveCount(1);
    expect($result['rows'][0][2])->toBe('Budi Santoso');
    expect($result['rows'][0][3])->toBe('CS1 Endang');
    expect($result['rows'][0][6])->toBe('PL200');
});

test('the same data with a leading NO URUT column reads identically to the plain layout', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    CsAgent::factory()->create(['name' => 'CS1 Endang']);
    Product::create(['trip_id' => $trip->id, 'product_code' => 'PL300', 'price' => 50000, 'weight_gram' => 100, 'status' => 'active']);

    // Empty cells in the middle (KOTA, WARNA, SIZE) are the case that would
    // expose a column shift done by re-indexing: gaps would collapse and later
    // cells would land in the wrong place.
    $plainRow = ['Admin', '1', 'Budi Santoso', 'CS1 Endang', '0812345', '', 'PL300', '', '', '50000', '10000', '', 'note', ''];

    $plain = app(OrderImportService::class)->readAndValidate(
        importFixtureFile([PLAIN_IMPORT_HEADER, $plainRow]), $trip
    );
    $withSequence = app(OrderImportService::class)->readAndValidate(
        importFixtureFile([array_merge(['NO URUT'], PLAIN_IMPORT_HEADER), array_merge(['7'], $plainRow)]), $trip
    );

    expect($withSequence['errors'])->toBe([]);
    expect($withSequence['rows'])->toEqual($plain['rows']);
});

test('a header that merely contains the word NO is not mistaken for the sequence column', function () {
    // The template's own second column is called "NO" — only a header that is
    // exactly NO URUT in the FIRST cell triggers the shift.
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    CsAgent::factory()->create(['name' => 'CS1 Endang']);
    Product::create(['trip_id' => $trip->id, 'product_code' => 'PL400', 'price' => 50000, 'weight_gram' => 100, 'status' => 'active']);

    $result = app(OrderImportService::class)->readAndValidate(importFixtureFile([
        PLAIN_IMPORT_HEADER,
        ['Admin', 'NO URUT', 'Budi Santoso', 'CS1 Endang', '0812345', '', 'PL400', '', '', '50000', '', '', '', ''],
    ]), $trip);

    expect($result['errors'])->toBe([]);
    expect($result['rows'][0][2])->toBe('Budi Santoso'); // nothing was shifted
});
