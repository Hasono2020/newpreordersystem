<?php

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Services\CombinedInvoiceService;

/*
 * "Download all invoices (PDF)" for a trip: one combined invoice per customer,
 * one customer per A5 page, alphabetical.
 *
 * Needs dompdf (composer require dompdf/dompdf). The tests that actually produce a
 * PDF skip themselves when it isn't installed; the data/pagination tests don't
 * need it. Page counts are read straight out of the PDF bytes (each page is a
 * "/Type /Page" object) — checked against a PDF reader while building this.
 */

function dompdfMissing(): bool
{
    return ! class_exists(\Dompdf\Dompdf::class);
}

function pdfPages(string $pdf): int
{
    return preg_match_all('#/Type\s*/Page(?![a-z])#', $pdf);
}

function invoiceCustomer($test, $admin, string $name, bool $withShippingArea = false): Customer
{
    $customer = $test->customer($admin);
    $customer->update(['name' => $name]);
    if (! $withShippingArea) {
        $customer->update(['default_shipping_area_id' => null]); // keep shipping out of the arithmetic
    }
    return $customer->fresh();
}

/** An order with $products items (one unit each) of $price — subtotal = products x price. */
function invoiceOrder($trip, $customer, $createdBy, int $products = 1, int $price = 100000, array $attrs = []): Order
{
    $order = Order::factory()->create(array_merge([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $createdBy->id,
        'shipping_area_id' => null, 'order_number' => null,
        'subtotal' => $products * $price, 'total_amount' => $products * $price,
        'discount_amount' => 0, 'shipping_fee' => 0, 'shipping_discount' => 0,
        'deposit_paid' => 0, 'payment_status' => 'unpaid',
    ], $attrs));

    for ($i = 1; $i <= $products; $i++) {
        $product = Product::create([
            'trip_id' => $trip->id, 'product_code' => 'INV' . fake()->unique()->numerify('######'),
            'price' => $price, 'weight_gram' => 100, 'status' => 'active',
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 1,
            'unit_price' => $price, 'line_total' => $price, 'status' => 'pending',
        ]);
    }
    return $order;
}

function invoiceGroup(string $code, int $variants): array
{
    return ['code' => $code, 'rows' => array_fill(0, $variants, ['label' => 'x', 'qty' => 1, 'unit_price' => 1, 'line_total' => 1, 'dim' => false])];
}

// ── The data behind each invoice ─────────────────────────────────────

test('there is one invoice per customer, alphabetical regardless of who ordered first', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();

    $zoe   = invoiceCustomer($this, $admin, 'Zoe Tan');
    $adi   = invoiceCustomer($this, $admin, 'adi Nugroho');   // lower-case on purpose
    $maria = invoiceCustomer($this, $admin, 'Maria Lim');

    invoiceOrder($trip, $zoe,   $admin, 1, 100000, ['ordered_at' => now()->subHours(3)]);
    invoiceOrder($trip, $maria, $admin, 1, 100000, ['ordered_at' => now()->subHours(2)]);
    invoiceOrder($trip, $adi,   $admin, 1, 100000, ['ordered_at' => now()->subHours(1)]);
    invoiceOrder($trip, $zoe,   $admin, 1, 100000, ['ordered_at' => now()]);  // Zoe's second order: same invoice, not a new one

    $invoices = app(CombinedInvoiceService::class)->forTrip($trip);

    expect($invoices->pluck('customer.name')->all())->toBe(['adi Nugroho', 'Maria Lim', 'Zoe Tan']);
    expect($invoices->firstWhere('customer.name', 'Zoe Tan')['order_count'])->toBe(2); // merged
});

test('an invoice carries the right figures', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = invoiceCustomer($this, $admin, 'Budi');
    $order    = invoiceOrder($trip, $customer, $admin, 3, 100000, ['deposit_paid' => 100000, 'payment_status' => 'partial']);
    Payment::factory()->create(['order_id' => $order->id, 'amount' => 100000, 'type' => 'deposit', 'paid_at' => now(), 'voided_at' => null, 'verification_status' => 'verified']);

    $inv = app(CombinedInvoiceService::class)->forTrip($trip)->first();

    expect($inv['totals']['subtotal'])->toBe(300000.0);
    expect($inv['totals']['grand_total'])->toBe(300000.0);   // no shipping area, no promo
    expect($inv['totals']['paid'])->toBe(100000.0);
    expect($inv['totals']['balance'])->toBe(200000.0);
    expect($inv['item_count'])->toBe(3);
    expect($inv['partial_count'])->toBe(1);
    expect($inv['delivery'])->toBeNull();                    // "No shipping area set"
});

test('payments listed on the invoice leave out voided ones and internal credit moves, and flag refunds', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = invoiceCustomer($this, $admin, 'Budi');
    $order    = invoiceOrder($trip, $customer, $admin);

    $make = fn (array $a) => Payment::factory()->create(array_merge(
        ['order_id' => $order->id, 'paid_at' => now(), 'voided_at' => null, 'verification_status' => 'verified'], $a));
    $make(['amount' => 500000, 'type' => 'deposit']);                                          // shown
    $make(['amount' => 70000,  'type' => 'refund', 'method' => 'Refund']);                      // shown, as a refund
    $make(['amount' => 90000,  'type' => 'partial', 'voided_at' => now()]);                    // voided: hidden
    $make(['amount' => 40000,  'type' => 'refund',  'method' => 'reallocation']);              // internal move: hidden

    $pays = app(CombinedInvoiceService::class)->forTrip($trip)->first()['payments'];

    expect($pays)->toHaveCount(2);
    expect(array_column($pays, 'amount'))->toEqualCanonicalizing([500000.0, 70000.0]);
    expect(collect($pays)->firstWhere('amount', 70000.0)['refund'])->toBeTrue();
    expect(collect($pays)->firstWhere('amount', 500000.0)['refund'])->toBeFalse();
});

test('staff who can only see their own orders get invoices for just those', function () {
    $admin = $this->adminUser();
    $staff = $this->ownDataStaff();
    $trip  = $this->openTrip();

    invoiceOrder($trip, invoiceCustomer($this, $admin, 'Mine'),   $staff);
    invoiceOrder($trip, invoiceCustomer($this, $admin, 'Theirs'), $admin);

    $names = app(CombinedInvoiceService::class)->forTrip($trip, $staff->id)->pluck('customer.name')->all();

    expect($names)->toBe(['Mine']);
});

test('the PDF figures are the ones the on-screen combined invoice shows — including shipping', function () {
    // The reason the maths lives in one service. Uses a customer WITH a default
    // shipping area so shipping is non-zero and any drift would show.
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = invoiceCustomer($this, $admin, 'Drift Check', withShippingArea: true);
    invoiceOrder($trip, $customer, $admin, 4, 250000);

    $inv = app(CombinedInvoiceService::class)->forTrip($trip)->first();
    expect($inv['totals']['shipping'])->toBeGreaterThan(0); // sanity: the test really exercises shipping

    $page = $this->actingAs($admin)->get(route('orders.combined-invoice', ['customer' => $customer->id, 'trip_id' => $trip->id]));

    $page->assertOk();
    $rp = fn ($n) => 'Rp ' . number_format($n, 0, ',', '.');
    $page->assertSeeText($rp($inv['totals']['subtotal']));
    $page->assertSeeText($rp($inv['totals']['shipping']));
    $page->assertSeeText($rp($inv['totals']['grand_total']));
    // Just the area's name: the on-screen page lays name and ", province" out across lines.
    $page->assertSeeText($customer->fresh()->defaultShippingArea->name);
});

// ── How items are laid out across pages (pure logic) ─────────────────

test('a short item list is a single chunk, balanced across both columns', function () {
    $chunks = CombinedInvoiceService::paginateGroups([invoiceGroup('A', 3), invoiceGroup('B', 3), invoiceGroup('C', 3)]);

    expect($chunks)->toHaveCount(1);
    expect($chunks[0]['left'])->not->toBeEmpty();
    expect($chunks[0]['right'])->not->toBeEmpty();
});

test('an empty item list produces no chunks', function () {
    expect(CombinedInvoiceService::paginateGroups([]))->toBe([]);
});

test('a lone product group stays in the left column', function () {
    $chunks = CombinedInvoiceService::paginateGroups([invoiceGroup('A', 5)]);

    expect($chunks)->toHaveCount(1);
    expect($chunks[0]['left'])->toHaveCount(1);
    expect($chunks[0]['right'])->toBe([]);
});

test('a product group too tall for a column is split, with its header repeated as (cont.)', function () {
    $chunks = CombinedInvoiceService::paginateGroups([invoiceGroup('BIG', 70)]);

    $codes = [];
    foreach ($chunks as $c) foreach (['left', 'right'] as $col) foreach ($c[$col] as $b) $codes[] = $b['code'];

    expect($codes[0])->toBe('BIG');
    expect($codes[1])->toBe('BIG (cont.)');
    // And not a single variant is lost in the split.
    $variants = 0;
    foreach ($chunks as $c) foreach (['left', 'right'] as $col) foreach ($c[$col] as $b) $variants += count($b['rows']);
    expect($variants)->toBe(70);
});

test('laying out items never loses, duplicates or reorders anything, whatever the shape', function () {
    mt_srand(11);
    for ($trial = 0; $trial < 150; $trial++) {
        $groups = [];
        for ($g = 1, $n = mt_rand(1, 40); $g <= $n; $g++) $groups[] = invoiceGroup("P$g", mt_rand(1, 9));

        $chunks = CombinedInvoiceService::paginateGroups($groups, mt_rand(8, 16), mt_rand(17, 29), 28);

        $seen = [];
        $variants = 0;
        foreach ($chunks as $c) {
            expect(count($c['left']) + count($c['right']))->toBeGreaterThan(0); // never an empty chunk
            foreach (['left', 'right'] as $col) {
                foreach ($c[$col] as $b) {
                    $variants += count($b['rows']);
                    $base = str_replace(' (cont.)', '', $b['code']);
                    if (end($seen) !== $base) $seen[] = $base;
                }
            }
        }
        expect($variants)->toBe(array_sum(array_map(fn ($g) => count($g['rows']), $groups)));
        expect($seen)->toBe(array_column($groups, 'code'));
    }
});

// ── The download itself (needs dompdf) ───────────────────────────────

test('downloading a trip gives a PDF file', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    invoiceOrder($trip, invoiceCustomer($this, $admin, 'Budi'), $admin);

    $response = $this->actingAs($admin)->get(route('trips.invoices.pdf', $trip));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/pdf');
    expect($response->headers->get('Content-Disposition'))->toContain('attachment')->toContain('.pdf');
    expect(substr($response->getContent(), 0, 5))->toBe('%PDF-');
})->skip(fn () => dompdfMissing(), 'dompdf is not installed (composer require dompdf/dompdf)');

test('every customer starts on a fresh page — three customers, three pages', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    foreach (['Adi', 'Budi', 'Citra'] as $name) {
        invoiceOrder($trip, invoiceCustomer($this, $admin, $name), $admin, 2);
    }

    $pdf = $this->actingAs($admin)->get(route('trips.invoices.pdf', $trip))->getContent();

    expect(pdfPages($pdf))->toBe(3);
})->skip(fn () => dompdfMissing(), 'dompdf is not installed (composer require dompdf/dompdf)');

test('a customer with a very long invoice runs onto more pages, and the next customer still starts fresh', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    invoiceOrder($trip, invoiceCustomer($this, $admin, 'Adi Big'), $admin, 80); // 80 product lines
    invoiceOrder($trip, invoiceCustomer($this, $admin, 'Zed Small'), $admin, 1);

    $pdf = $this->actingAs($admin)->get(route('trips.invoices.pdf', $trip))->getContent();

    expect(pdfPages($pdf))->toBeGreaterThanOrEqual(3); // Adi: 2+ pages, then Zed on his own
})->skip(fn () => dompdfMissing(), 'dompdf is not installed (composer require dompdf/dompdf)');

test('awkward characters in a customer name or address do not break the PDF', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = invoiceCustomer($this, $admin, "Dr. O'Brien & <b>Sons</b> \"Quoted\"");
    $customer->update(['address' => "Rue d'Été — Zürich <script>alert(1)</script>"]);
    invoiceOrder($trip, $customer, $admin);

    $response = $this->actingAs($admin)->get(route('trips.invoices.pdf', $trip));

    $response->assertOk();
    expect(pdfPages($response->getContent()))->toBe(1);
})->skip(fn () => dompdfMissing(), 'dompdf is not installed (composer require dompdf/dompdf)');

test('a bigger trip comes back as one zip of several PDFs, split in alphabetical order', function () {
    config(['invoices.pdf_customers_per_file' => 2]);

    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    foreach (['Ella', 'Adi', 'Dewi', 'Budi', 'Citra'] as $name) {          // 5 customers, 2 per file -> 3 files
        invoiceOrder($trip, invoiceCustomer($this, $admin, $name), $admin);
    }

    $response = $this->actingAs($admin)->get(route('trips.invoices.pdf', $trip));
    $response->assertOk();

    $path = $response->baseResponse->getFile()->getPathname();
    $zip  = new ZipArchive();
    expect($zip->open($path))->toBeTrue();

    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) $names[] = $zip->getNameIndex($i);
    sort($names);

    expect($names)->toHaveCount(3);
    expect($names[0])->toContain('part-01-of-03')->toContain('adi-to-budi');
    expect($names[1])->toContain('part-02-of-03')->toContain('citra-to-dewi');
    expect($names[2])->toContain('part-03-of-03')->toContain('ella-to-ella');

    $pageCounts = [];
    foreach ($names as $name) {
        $bytes = $zip->getFromName($name);
        expect(substr($bytes, 0, 5))->toBe('%PDF-');
        $pageCounts[] = pdfPages($bytes);
    }
    expect($pageCounts)->toBe([2, 2, 1]);
    $zip->close();
})->skip(fn () => dompdfMissing(), 'dompdf is not installed (composer require dompdf/dompdf)');

test('a trip over the size limit is offered a background build instead of timing out', function () {
    config(['invoices.pdf_customers_per_file' => 2, 'invoices.pdf_max_customers' => 3]);

    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    foreach (['A', 'B', 'C', 'D'] as $name) {
        invoiceOrder($trip, invoiceCustomer($this, $admin, $name), $admin);
    }

    // Too big to build while the browser waits: no PDF, no error page — it lands on the page
    // that offers to build it in the background, with the trip already chosen.
    $response = $this->actingAs($admin)->from(route('orders.index'))->get(route('trips.invoices.pdf', $trip));

    $response->assertRedirect(route('invoice-exports.index', ['trip' => $trip->id]));
    $response->assertSessionHas('warning');
    expect(session('warning'))->toContain('4 customers');
})->skip(fn () => dompdfMissing(), 'dompdf is not installed (composer require dompdf/dompdf)');

test('a trip with no orders says so instead of producing a blank PDF', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();

    $response = $this->actingAs($admin)->from(route('orders.index'))->get(route('trips.invoices.pdf', $trip));

    $response->assertRedirect(route('orders.index'));
    $response->assertSessionHas('error');
})->skip(fn () => dompdfMissing(), 'dompdf is not installed (composer require dompdf/dompdf)');

test('downloading is recorded in the Activity Log', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    invoiceOrder($trip, invoiceCustomer($this, $admin, 'Budi'), $admin);

    $this->actingAs($admin)->get(route('trips.invoices.pdf', $trip))->assertOk();

    expect(ActivityLog::where('action', 'invoice.trip_pdf_downloaded')->count())->toBe(1);
})->skip(fn () => dompdfMissing(), 'dompdf is not installed (composer require dompdf/dompdf)');

// ── Access and the buttons ───────────────────────────────────────────

test('staff without the export permission cannot download everyone\'s invoices', function () {
    $admin = $this->adminUser();
    $staff = $this->staffUser(['permissions' => ['orders.export' => false]]);
    $trip  = $this->openTrip();
    invoiceOrder($trip, invoiceCustomer($this, $admin, 'Budi'), $admin);

    $this->actingAs($staff)->get(route('trips.invoices.pdf', $trip))->assertForbidden();
});

test('without the PDF library installed you get a plain instruction, not a crash', function () {
    // Only meaningful where dompdf is genuinely absent — that is the situation
    // right after deploying this and before running composer on the server.
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    invoiceOrder($trip, invoiceCustomer($this, $admin, 'Budi'), $admin);

    $response = $this->actingAs($admin)->from(route('orders.index'))->get(route('trips.invoices.pdf', $trip));

    $response->assertRedirect(route('orders.index'));
    expect(session('error'))->toContain('composer require dompdf/dompdf');
})->skip(fn () => ! dompdfMissing(), 'dompdf IS installed, so this fallback cannot be reached');

test('the Orders page offers the download once a trip is chosen, and says to pick one first otherwise', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();

    $this->actingAs($admin)->get(route('orders.index', ['trip_id' => $trip->id]))
        ->assertOk()->assertSee(route('trips.invoices.pdf', $trip), false)->assertSee('Download all invoices (PDF)');

    $this->actingAs($admin)->get(route('orders.index'))
        ->assertOk()->assertSee('Pick a trip first')->assertDontSee(route('trips.invoices.pdf', $trip), false);
});

test('the Trip page has an Invoices PDF button', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();

    $this->actingAs($admin)->get(route('trips.show', $trip))
        ->assertOk()->assertSee(route('trips.invoices.pdf', $trip), false)->assertSee('Invoices PDF');
});
