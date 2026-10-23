<?php

use App\Jobs\GenerateInvoiceExportPartJob;
use App\Models\ActivityLog;
use App\Models\CsAgent;
use App\Models\InvoiceExport;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\CombinedInvoiceService;
use Illuminate\Support\Facades\Queue;

/*
 * The invoice PDF download has the same optional CS checklist as the Excel export.
 * A customer is included when at least ONE of their orders in the trip was handled by a
 * ticked CS; their invoice still carries ALL their orders, so its total stays what they owe.
 * Nothing ticked = every customer, exactly as before.
 */

function csfCustomer($test, $admin, string $name)
{
    $customer = $test->customer($admin);
    $customer->update(['name' => $name, 'default_shipping_area_id' => null]);
    return $customer->fresh();
}

function csfOrder($trip, $customer, $createdBy, ?CsAgent $cs, int $price = 10000): Order
{
    $order = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $createdBy->id,
        'cs_agent_id' => $cs?->id,
        'shipping_area_id' => null, 'order_number' => null,
        'subtotal' => $price, 'total_amount' => $price, 'discount_amount' => 0, 'shipping_fee' => 0, 'shipping_discount' => 0,
        'deposit_paid' => 0, 'payment_status' => 'unpaid',
    ]);
    $product = Product::create([
        'trip_id' => $trip->id, 'product_code' => 'CSF' . fake()->unique()->numerify('######'),
        'price' => $price, 'weight_gram' => 100, 'status' => 'active',
    ]);
    OrderItem::create([
        'order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 1,
        'unit_price' => $price, 'line_total' => $price, 'status' => 'pending',
    ]);
    return $order;
}

/**
 * Rina handled Adi and (one of) Budi's orders; Dewi handled Citra and Budi's other order;
 * Eka's order has no CS at all.
 */
function csfScenario($test): array
{
    $admin = $test->adminUser();
    $trip  = $test->openTrip();
    $rina  = CsAgent::factory()->create(['name' => 'Rina']);
    $dewi  = CsAgent::factory()->create(['name' => 'Dewi']);

    $adi   = csfCustomer($test, $admin, 'Adi');
    $budi  = csfCustomer($test, $admin, 'Budi');
    $citra = csfCustomer($test, $admin, 'Citra');
    $eka   = csfCustomer($test, $admin, 'Eka');

    csfOrder($trip, $adi,   $admin, $rina);
    csfOrder($trip, $budi,  $admin, $rina);
    csfOrder($trip, $budi,  $admin, $dewi);
    csfOrder($trip, $citra, $admin, $dewi);
    csfOrder($trip, $eka,   $admin, null);

    return compact('admin', 'trip', 'rina', 'dewi', 'adi', 'budi', 'citra', 'eka');
}

// ── The customer list ───────────────────────────────────────────────

test('nothing ticked gives every customer', function () {
    $s = csfScenario($this);
    $svc = app(CombinedInvoiceService::class);

    expect($svc->forTrip($s['trip'])->pluck('customer.name')->all())->toBe(['Adi', 'Budi', 'Citra', 'Eka']);
    expect($svc->forTrip($s['trip'], null, [])->pluck('customer.name')->all())->toBe(['Adi', 'Budi', 'Citra', 'Eka']);
    expect($svc->customerIdsForTrip($s['trip'], null, []))->toHaveCount(4);
    expect($svc->countCustomers($s['trip']))->toBe(4);
});

test('one CS gives the customers who have at least one order of theirs', function () {
    $s = csfScenario($this);
    $svc = app(CombinedInvoiceService::class);
    $pick = [(string) $s['rina']->id];

    expect($svc->forTrip($s['trip'], null, $pick)->pluck('customer.name')->all())->toBe(['Adi', 'Budi']);
    expect($svc->customerIdsForTrip($s['trip'], null, $pick))->toBe([$s['adi']->id, $s['budi']->id]);
    expect($svc->countCustomers($s['trip'], null, $pick))->toBe(2);
});

test('several CS together, and a customer served by two of them is counted once', function () {
    $s = csfScenario($this);
    $svc = app(CombinedInvoiceService::class);
    $pick = [(string) $s['rina']->id, (string) $s['dewi']->id];

    expect($svc->forTrip($s['trip'], null, $pick)->pluck('customer.name')->all())->toBe(['Adi', 'Budi', 'Citra']);
    expect($svc->countCustomers($s['trip'], null, $pick))->toBe(3);
});

test('"No CS agent assigned" picks the customers whose order has no CS', function () {
    $s = csfScenario($this);
    $svc = app(CombinedInvoiceService::class);

    expect($svc->forTrip($s['trip'], null, ['none'])->pluck('customer.name')->all())->toBe(['Eka']);
    expect($svc->forTrip($s['trip'], null, ['none', (string) $s['dewi']->id])->pluck('customer.name')->all())
        ->toBe(['Budi', 'Citra', 'Eka']);
});

test('a filtered customer still gets ALL their orders on the invoice, so the total is right', function () {
    $s = csfScenario($this);

    // Budi has two orders (10.000 each): one by Rina, one by Dewi. Picking only Rina must still show 20.000.
    $inv = app(CombinedInvoiceService::class)->forTrip($s['trip'], null, [(string) $s['rina']->id])->firstWhere('customer.name', 'Budi');

    expect($inv['order_count'])->toBe(2);
    expect($inv['totals']['grand_total'])->toBe(20000.0);
});

test('garbage in the checklist matches nothing instead of widening to everyone', function () {
    $s = csfScenario($this);
    $svc = app(CombinedInvoiceService::class);

    expect($svc->forTrip($s['trip'], null, ['abc'])->count())->toBe(0);
    expect($svc->countCustomers($s['trip'], null, ['abc']))->toBe(0);
});

test('staff who only see their own orders never get customers outside them, even when a CS is ticked', function () {
    $admin = $this->adminUser();
    $staff = $this->ownDataStaff();
    $trip  = $this->openTrip();
    $rina  = CsAgent::factory()->create();
    $mine   = csfCustomer($this, $admin, 'Mine');
    $theirs = csfCustomer($this, $admin, 'Theirs');
    csfOrder($trip, $mine, $staff, $rina);
    csfOrder($trip, $theirs, $admin, $rina);

    $svc = app(CombinedInvoiceService::class);
    expect($svc->customerIdsForTrip($trip, $staff->id, [(string) $rina->id]))->toBe([$mine->id]);
    expect($svc->countCustomers($trip, $staff->id, [(string) $rina->id]))->toBe(1);
});

// ── The pages ───────────────────────────────────────────────────────

test('the Orders page and the Trip page both offer the CS pop-up for the invoices', function () {
    $s = csfScenario($this);

    $this->actingAs($s['admin'])->get(route('orders.index', ['trip_id' => $s['trip']->id]))
        ->assertOk()->assertSee('invoicePdfModal', false)->assertSee('Rina')->assertSee('No CS agent assigned');

    $this->actingAs($s['admin'])->get(route('trips.show', $s['trip']))
        ->assertOk()->assertSee('invoicePdfModal', false)->assertSee('Dewi');
});

// ── The instant download ────────────────────────────────────────────

test('downloading with a CS ticked says how many customers when the trip is too big for instant', function () {
    config(['invoices.pdf_customers_per_file' => 1, 'invoices.pdf_max_customers' => 1]);
    $s = csfScenario($this);

    // Rina has 2 customers, more than the limit of 1 → handed to the background build, with the CS kept.
    $this->actingAs($s['admin'])
        ->get(route('trips.invoices.pdf', ['trip' => $s['trip'], 'cs_agent_ids' => [$s['rina']->id]]))
        ->assertRedirect(route('invoice-exports.index', ['trip' => $s['trip']->id, 'cs_agent_ids' => [(string) $s['rina']->id]]));
})->skip(fn () => ! class_exists(\Dompdf\Dompdf::class), 'dompdf is not installed (composer require dompdf/dompdf)');

test('downloading for a CS nobody handled says so instead of giving an empty file', function () {
    $s = csfScenario($this);
    $nobody = CsAgent::factory()->create(['name' => 'Nobody']);

    $this->actingAs($s['admin'])->from(route('orders.index'))
        ->get(route('trips.invoices.pdf', ['trip' => $s['trip'], 'cs_agent_ids' => [$nobody->id]]))
        ->assertRedirect(route('orders.index'))
        ->assertSessionHas('error', fn ($m) => str_contains($m, 'Nobody'));
})->skip(fn () => ! class_exists(\Dompdf\Dompdf::class), 'dompdf is not installed (composer require dompdf/dompdf)');

test('a filtered download is a PDF with one page per matching customer, and the log says which CS', function () {
    $s = csfScenario($this);

    $response = $this->actingAs($s['admin'])
        ->get(route('trips.invoices.pdf', ['trip' => $s['trip'], 'cs_agent_ids' => [$s['dewi']->id]]));

    $response->assertOk();
    expect(substr($response->getContent(), 0, 5))->toBe('%PDF-');
    expect(preg_match_all('#/Type\s*/Page(?![a-z])#', $response->getContent()))->toBe(2);   // Budi + Citra

    $log = ActivityLog::where('action', 'invoice.trip_pdf_downloaded')->latest('id')->first();
    expect($log->description)->toContain('2 customer(s)')->toContain('CS: Dewi');
})->skip(fn () => ! class_exists(\Dompdf\Dompdf::class), 'dompdf is not installed (composer require dompdf/dompdf)');

// ── The background build ────────────────────────────────────────────

test('the offer page shows the chosen CS and passes it on to the Start button', function () {
    $s = csfScenario($this);

    $this->actingAs($s['admin'])
        ->get(route('invoice-exports.index', ['trip' => $s['trip']->id, 'cs_agent_ids' => [$s['rina']->id]]))
        ->assertOk()
        ->assertSee('Only customers with an order handled by')
        ->assertSee('Rina')
        ->assertSee('name="cs_agent_ids[]" value="' . $s['rina']->id . '"', false);
});

test('starting a filtered build records only the matching customers', function () {
    Queue::fake();
    $s = csfScenario($this);

    $this->actingAs($s['admin'])
        ->post(route('trips.invoice-exports.store', $s['trip']), ['cs_agent_ids' => [$s['dewi']->id]])
        ->assertRedirect(route('invoice-exports.index'))->assertSessionHas('success');

    $export = InvoiceExport::firstOrFail();
    expect($export->customer_ids)->toBe([$s['budi']->id, $s['citra']->id]);
    expect($export->total_customers)->toBe(2);

    Queue::assertPushed(GenerateInvoiceExportPartJob::class);
    expect(ActivityLog::where('action', 'invoice.export_started')->first()->description)->toContain('CS: Dewi');
})->skip(fn () => ! class_exists(\Dompdf\Dompdf::class), 'dompdf is not installed (composer require dompdf/dompdf)');
