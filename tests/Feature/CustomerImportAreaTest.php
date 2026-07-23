<?php

use App\Models\Customer;
use App\Models\ShippingArea;
use App\Traits\HandlesXlsx;

/*
 * Tests for shipping-area resolution during customer import.
 *
 * Reported symptom: a spreadsheet whose shipping_area column was filled in
 * imported "successfully", but the customers came out with no area.
 *
 * Two defects behind that:
 *  1. ReportController::importCustomers resolved the area with a substring
 *     scan only (no exact match), and when nothing matched it left the id
 *     null and imported the customer anyway - reporting success while
 *     silently dropping the area.
 *  2. Both importers used first-match-wins substring matching, so a short
 *     area name (e.g. "JAK") could swallow longer ones ("JAKBAR", "JAKPUS",
 *     "JAKUT") depending on arbitrary database row order.
 */

class CustImportXlsx
{
    use HandlesXlsx;
    public function build(array $rows): string { return $this->buildXlsx($rows); }
}

function custImportFile(array $dataRows): \Illuminate\Http\UploadedFile
{
    $rows = array_merge(
        [['name', 'phone', 'type', 'shipping_area', 'address', 'notes']],
        $dataRows
    );
    return \Illuminate\Http\UploadedFile::fake()
        ->createWithContent('customers.xlsx', (new CustImportXlsx())->build($rows));
}

test('an exact area name is resolved and stored on the customer', function () {
    $admin = $this->adminUser();
    $area  = ShippingArea::factory()->create(['name' => 'SURABAYA']);

    $this->actingAs($admin)->post(route('customers.import'), [
        'file' => custImportFile([['JASMINE 7911', '081234567890', 'customer', 'SURABAYA', '', '']]),
    ])->assertRedirect();

    $customer = Customer::where('name', 'JASMINE 7911')->first();
    expect($customer)->not->toBeNull()
        ->and($customer->default_shipping_area_id)->toBe($area->id);
});

test('area matching is case and whitespace insensitive', function () {
    $admin = $this->adminUser();
    $area  = ShippingArea::factory()->create(['name' => 'Surabaya']);

    $this->actingAs($admin)->post(route('customers.import'), [
        'file' => custImportFile([['TONO 5566', '081234567891', 'customer', '  surabaya  ', '', '']]),
    ])->assertRedirect();

    expect(Customer::where('name', 'TONO 5566')->first()->default_shipping_area_id)->toBe($area->id);
});

test('a short area name does not hijack a longer one that also matches', function () {
    $admin = $this->adminUser();
    // "JAK" is a substring of "JAKBAR" - first-match-wins used to assign
    // whichever row the database returned first.
    $jak    = ShippingArea::factory()->create(['name' => 'JAK']);
    $jakbar = ShippingArea::factory()->create(['name' => 'JAKBAR']);

    $this->actingAs($admin)->post(route('customers.import'), [
        'file' => custImportFile([['CELINE LISKA', '081234567892', 'reseller', 'JAKBAR', '', '']]),
    ])->assertRedirect();

    $customer = Customer::where('name', 'CELINE LISKA')->first();
    expect($customer->default_shipping_area_id)->toBe($jakbar->id)
        ->and($customer->default_shipping_area_id)->not->toBe($jak->id);
});

test('an unmatched area name is reported by name instead of being silently dropped', function () {
    $admin = $this->adminUser();
    ShippingArea::factory()->create(['name' => 'SURABAYA']);

    $response = $this->actingAs($admin)->post(route('customers.import'), [
        'file' => custImportFile([['VEBBIE 6423', '081234567893', 'customer', 'JAKBAR', '', '']]),
    ]);

    // Not imported with a null area...
    expect(Customer::where('name', 'VEBBIE 6423')->exists())->toBeFalse();

    // ...and the missing name is named explicitly so it can be created.
    $flash = session('warning') ?? session('success');
    expect($flash)->toContain('JAKBAR');
});

test('an area with a blank name cannot match every lookup', function () {
    $admin = $this->adminUser();
    // A blank key makes str_contains() return true for ANY needle, which
    // would assign this area to every imported customer.
    ShippingArea::factory()->create(['name' => '']);
    $real = ShippingArea::factory()->create(['name' => 'MEDAN']);

    $this->actingAs($admin)->post(route('customers.import'), [
        'file' => custImportFile([['KIKI 0808', '081234567894', 'customer', 'MEDAN', '', '']]),
    ])->assertRedirect();

    expect(Customer::where('name', 'KIKI 0808')->first()->default_shipping_area_id)->toBe($real->id);
});

test('the reports importer no longer saves a customer with a silently null area', function () {
    $admin = $this->adminUser();
    ShippingArea::factory()->create(['name' => 'SURABAYA']);

    $this->actingAs($admin)->post(route('reports.import.customers'), [
        'file' => custImportFile([['GHOST 0001', '081234567895', 'customer', 'NOWHERE_LAND', '', '']]),
    ]);

    expect(Customer::where('name', 'GHOST 0001')->exists())->toBeFalse();
});

test('the reports importer records who imported the customer', function () {
    $admin = $this->adminUser();
    ShippingArea::factory()->create(['name' => 'SURABAYA']);

    $this->actingAs($admin)->post(route('reports.import.customers'), [
        'file' => custImportFile([['ATTRIBUTED 1', '081234567896', 'customer', 'SURABAYA', '', '']]),
    ]);

    expect(Customer::where('name', 'ATTRIBUTED 1')->first()->created_by)->toBe($admin->id);
});