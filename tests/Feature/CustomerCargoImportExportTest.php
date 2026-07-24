<?php

use App\Models\Customer;
use App\Models\ShippingArea;
use App\Traits\HandlesXlsx;

/*
 * use_cargo was added to the Customer model, its create/edit forms, and
 * the order/invoice display - but the customer export/template/import
 * never got it, so it was invisible in spreadsheets even though it's a
 * real, editable field everywhere else in the app.
 *
 * Appended as a NEW LAST column (not inserted between existing ones),
 * matching how every other column addition in this app has been handled -
 * so a file exported before this field existed still re-imports at the
 * same column positions it always did, with cargo defaulting to "No".
 */

class CargoImportXlsx
{
    use HandlesXlsx;
    public function build(array $rows): string { return $this->buildXlsx($rows); }
    public function read(string $path): array { return $this->readXlsx($path); }
}

function cargoImportFile(array $dataRows): \Illuminate\Http\UploadedFile
{
    $rows = array_merge(
        [['name', 'phone', 'type', 'shipping_area', 'address', 'notes', 'use_cargo']],
        $dataRows
    );
    return \Illuminate\Http\UploadedFile::fake()
        ->createWithContent('customers.xlsx', (new CargoImportXlsx())->build($rows));
}

test('export includes a use_cargo column reflecting each customer\'s actual setting', function () {
    $admin = $this->adminUser();
    $area  = $this->shippingArea();
    Customer::factory()->create(['name' => 'Cargo Yes', 'default_shipping_area_id' => $area->id, 'use_cargo' => true, 'created_by' => $admin->id]);
    Customer::factory()->create(['name' => 'Cargo No', 'default_shipping_area_id' => $area->id, 'use_cargo' => false, 'created_by' => $admin->id]);

    $response = $this->actingAs($admin)->get(route('customers.export'));
    $response->assertOk();

    $rows   = (new CargoImportXlsx())->read($response->baseResponse->getFile()->getPathname());
    $header = $rows[0];
    $col    = array_search('use_cargo', $header);
    expect($col)->not->toBeFalse();

    $byName = collect(array_slice($rows, 1))->keyBy(fn($r) => $r[array_search('name', $header)]);
    expect($byName['Cargo Yes'][$col])->toBe('Yes')
        ->and($byName['Cargo No'][$col])->toBe('No');
});

test('importing "Yes" in the use_cargo column sets the flag', function () {
    $admin = $this->adminUser();
    ShippingArea::factory()->create(['name' => 'SURABAYA']);

    $this->actingAs($admin)->post(route('customers.import'), [
        'file' => cargoImportFile([['Cargo Import Yes', '081234599001', 'customer', 'SURABAYA', '', '', 'Yes']]),
    ])->assertRedirect();

    expect(Customer::where('name', 'Cargo Import Yes')->first()->use_cargo)->toBeTrue();
});

test('importing "No" (or blank) leaves the flag false', function () {
    $admin = $this->adminUser();
    ShippingArea::factory()->create(['name' => 'SURABAYA']);

    $this->actingAs($admin)->post(route('customers.import'), [
        'file' => cargoImportFile([['Cargo Import No', '081234599002', 'customer', 'SURABAYA', '', '', 'No']]),
    ])->assertRedirect();

    expect(Customer::where('name', 'Cargo Import No')->first()->use_cargo)->toBeFalse();
});

test('importing a file with no use_cargo column at all (older export format) defaults to false', function () {
    $admin = $this->adminUser();
    ShippingArea::factory()->create(['name' => 'SURABAYA']);

    // Deliberately the OLD 6-column shape, no 7th column whatsoever —
    // simulates a spreadsheet exported before this field existed.
    $rows = [
        ['name', 'phone', 'type', 'shipping_area', 'address', 'notes'],
        ['Legacy Import', '081234599003', 'customer', 'SURABAYA', '', ''],
    ];
    $file = \Illuminate\Http\UploadedFile::fake()
        ->createWithContent('legacy.xlsx', (new CargoImportXlsx())->build($rows));

    $this->actingAs($admin)->post(route('customers.import'), ['file' => $file])->assertRedirect();

    $customer = Customer::where('name', 'Legacy Import')->first();
    expect($customer)->not->toBeNull()
        ->and($customer->use_cargo)->toBeFalse();
});
