<?php

use App\Jobs\GenerateInvoiceExportPartJob;
use App\Models\ActivityLog;
use App\Models\InvoiceExport;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\CombinedInvoiceService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
 * A trip with thousands of customers can't be built while the browser waits, so
 * "Download all invoices" hands it to a background build: the customers (A–Z) are cut
 * into parts, each part is one short queue job that saves a PDF, and the last part
 * zips them into one file. These tests run that whole chain for real (the test queue
 * is synchronous), including the PDFs and the ZIP.
 */

function bgNoPdf(): bool
{
    return ! class_exists(\Dompdf\Dompdf::class);
}

function bgCustomer($test, $admin, string $name)
{
    $customer = $test->customer($admin);
    $customer->update(['name' => $name, 'default_shipping_area_id' => null]);
    return $customer->fresh();
}

function bgOrder($trip, $customer, $createdBy, int $price = 10000): Order
{
    $order = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $createdBy->id,
        'shipping_area_id' => null, 'order_number' => null,
        'subtotal' => $price, 'total_amount' => $price, 'discount_amount' => 0, 'shipping_fee' => 0, 'shipping_discount' => 0,
        'deposit_paid' => 0, 'payment_status' => 'unpaid',
    ]);
    $product = Product::create([
        'trip_id' => $trip->id, 'product_code' => 'BG' . fake()->unique()->numerify('######'),
        'price' => $price, 'weight_gram' => 100, 'status' => 'active',
    ]);
    OrderItem::create([
        'order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 1,
        'unit_price' => $price, 'line_total' => $price, 'status' => 'pending',
    ]);
    return $order;
}

/** A trip with one order for each named customer. Returns [trip, [name => customer]]. */
function bgTripWith($test, $admin, array $names, $createdBy = null): array
{
    $trip = $test->openTrip();
    $made = [];
    foreach ($names as $name) {
        $made[$name] = bgCustomer($test, $admin, $name);
        bgOrder($trip, $made[$name], $createdBy ?? $admin);
    }
    return [$trip, $made];
}

// ── The customer list and the slice builder ─────────────────────────

test('the customer list has every customer once, A–Z whatever the case or who ordered first', function () {
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $zoe   = bgCustomer($this, $admin, 'zoe tan');
    $adi   = bgCustomer($this, $admin, 'Adi Nugroho');
    $maria = bgCustomer($this, $admin, 'Maria Lim');

    bgOrder($trip, $zoe, $admin);
    bgOrder($trip, $maria, $admin);
    bgOrder($trip, $maria, $admin);   // two orders — still one customer
    bgOrder($trip, $adi, $admin);

    $ids = app(CombinedInvoiceService::class)->customerIdsForTrip($trip);

    expect($ids)->toBe([$adi->id, $maria->id, $zoe->id]);
});

test('staff who only see their own orders get only their own customers in the list', function () {
    $admin = $this->adminUser();
    $staff = $this->ownDataStaff();
    $trip  = $this->openTrip();
    $mine   = bgCustomer($this, $admin, 'Mine');
    $theirs = bgCustomer($this, $admin, 'Theirs');
    bgOrder($trip, $mine, $staff);
    bgOrder($trip, $theirs, $admin);

    expect(app(CombinedInvoiceService::class)->customerIdsForTrip($trip, $staff->id))->toBe([$mine->id]);
});

test('building a slice gives just those customers, in the order the ids were given', function () {
    $admin = $this->adminUser();
    [$trip, $c] = bgTripWith($this, $admin, ['Adi', 'Budi', 'Citra']);

    $slice = app(CombinedInvoiceService::class)->forCustomers($trip, [$c['Citra']->id, $c['Adi']->id]);

    expect($slice->pluck('customer.name')->all())->toBe(['Citra', 'Adi']);   // not Budi; and in the order asked
    expect(app(CombinedInvoiceService::class)->forCustomers($trip, []))->toHaveCount(0);
});

// ── Starting a build ────────────────────────────────────────────────

test('starting a build records every customer A–Z and queues the first part', function () {
    config(['invoices.background_customers_per_file' => 2]);
    Queue::fake();

    $admin = $this->adminUser();
    [$trip, $c] = bgTripWith($this, $admin, ['Ella', 'Adi', 'Dewi', 'Budi', 'Citra']);

    $this->actingAs($admin)->post(route('trips.invoice-exports.store', $trip))
        ->assertRedirect(route('invoice-exports.index'))->assertSessionHas('success');

    $export = InvoiceExport::firstOrFail();
    expect($export->status)->toBe('queued');
    expect($export->total_customers)->toBe(5);
    expect($export->total_parts)->toBe(3);                         // 5 customers, 2 per part
    expect($export->customer_ids)->toBe([$c['Adi']->id, $c['Budi']->id, $c['Citra']->id, $c['Dewi']->id, $c['Ella']->id]);
    expect($export->created_by)->toBe($admin->id);
    expect($export->owner_id)->toBeNull();

    Queue::assertPushed(GenerateInvoiceExportPartJob::class, fn ($job) => $job->exportId === $export->id && $job->part === 0);
    expect(ActivityLog::where('action', 'invoice.export_started')->count())->toBe(1);
})->skip(fn () => bgNoPdf(), 'dompdf is not installed (composer require dompdf/dompdf)');

test('pressing start twice does not start two builds of the same trip', function () {
    Queue::fake();

    $admin = $this->adminUser();
    [$trip] = bgTripWith($this, $admin, ['Adi', 'Budi']);

    $this->actingAs($admin)->post(route('trips.invoice-exports.store', $trip));
    $this->actingAs($admin)->post(route('trips.invoice-exports.store', $trip))->assertSessionHas('warning');

    expect(InvoiceExport::count())->toBe(1);
    Queue::assertPushed(GenerateInvoiceExportPartJob::class, 1);
})->skip(fn () => bgNoPdf(), 'dompdf is not installed (composer require dompdf/dompdf)');

test('a trip with no orders is not started', function () {
    Queue::fake();
    $admin = $this->adminUser();

    $this->actingAs($admin)->post(route('trips.invoice-exports.store', $this->openTrip()))->assertSessionHas('error');

    expect(InvoiceExport::count())->toBe(0);
    Queue::assertNothingPushed();
})->skip(fn () => bgNoPdf(), 'dompdf is not installed (composer require dompdf/dompdf)');

test('staff who only see their own orders build only their own', function () {
    Queue::fake();

    $admin = $this->adminUser();
    $staff = User::factory()->ownDataOnly()->create(['permissions' => ['orders.export' => true]]);
    $trip  = $this->openTrip();
    $mine  = bgCustomer($this, $admin, 'Mine');
    bgOrder($trip, $mine, $staff);
    bgOrder($trip, bgCustomer($this, $admin, 'Theirs'), $admin);

    $this->actingAs($staff)->post(route('trips.invoice-exports.store', $trip));

    $export = InvoiceExport::firstOrFail();
    expect($export->customer_ids)->toBe([$mine->id]);
    expect($export->owner_id)->toBe($staff->id);
})->skip(fn () => bgNoPdf(), 'dompdf is not installed (composer require dompdf/dompdf)');

// ── The whole chain, for real ───────────────────────────────────────

test('the whole chain runs: parts are built, zipped into one file, and the parts removed', function () {
    config(['invoices.background_customers_per_file' => 2]);
    Storage::fake('local');

    $admin = $this->adminUser();
    [$trip] = bgTripWith($this, $admin, ['Ella', 'Adi', 'Dewi', 'Budi', 'Citra']);

    // The test queue is synchronous, so this runs every part, then the zip, before it returns.
    $this->actingAs($admin)->post(route('trips.invoice-exports.store', $trip))->assertSessionDoesntHaveErrors();

    $export = InvoiceExport::firstOrFail();
    expect($export->status)->toBe('done');
    expect($export->parts_done)->toBe(3);
    expect($export->processed_customers)->toBe(5);
    expect($export->percent())->toBe(100);
    expect($export->file_size)->toBeGreaterThan(0);
    expect($export->finished_at)->not->toBeNull();

    $zip = new ZipArchive();
    expect($zip->open(Storage::disk('local')->path($export->file_path)))->toBeTrue();

    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
        expect($zip->getFromIndex($i))->toStartWith('%PDF');              // each part is a real PDF
    }
    sort($names);
    expect($names)->toBe([
        'part-0001-of-0003_adi-to-budi.pdf',
        'part-0002-of-0003_citra-to-dewi.pdf',
        'part-0003-of-0003_ella-to-ella.pdf',
    ]);
    $zip->close();

    // Only the ZIP is left on disk — the separate parts were removed.
    $files = Storage::disk('local')->files($export->directory());
    expect($files)->toHaveCount(1);
    expect($files[0])->toEndWith('.zip');
})->skip(fn () => bgNoPdf(), 'dompdf is not installed (composer require dompdf/dompdf)');

test('a build that fits in one part still works', function () {
    Storage::fake('local');
    $admin = $this->adminUser();
    [$trip] = bgTripWith($this, $admin, ['Adi', 'Budi']);   // default 50 per part → 1 part

    $this->actingAs($admin)->post(route('trips.invoice-exports.store', $trip));

    $export = InvoiceExport::firstOrFail();
    expect($export->status)->toBe('done');
    expect($export->total_parts)->toBe(1);
})->skip(fn () => bgNoPdf(), 'dompdf is not installed (composer require dompdf/dompdf)');

// ── Stopping, failing, and removing ─────────────────────────────────

test('a removed build is gone with its files, and a job still queued for it does nothing', function () {
    Storage::fake('local');
    $admin = $this->adminUser();
    [$trip, $c] = bgTripWith($this, $admin, ['Adi']);

    $export = InvoiceExport::create([
        'trip_id' => $trip->id, 'created_by' => $admin->id, 'status' => 'running',
        'customer_ids' => [$c['Adi']->id], 'total_customers' => 1, 'customers_per_part' => 50, 'total_parts' => 1,
    ]);
    Storage::disk('local')->put($export->directory() . '/part-0001-of-0001_a-to-a.pdf', '%PDF-1.4 test');

    $this->actingAs($admin)->delete(route('invoice-exports.destroy', $export))->assertSessionHas('success');

    expect(InvoiceExport::find($export->id))->toBeNull();
    expect(Storage::disk('local')->exists($export->directory()))->toBeFalse();

    // The job that was already waiting on the queue finds nothing and quietly stops.
    (new GenerateInvoiceExportPartJob($export->id, 0))->handle(app(CombinedInvoiceService::class), app(\App\Services\InvoicePdfRenderer::class));
    expect(Storage::disk('local')->allFiles('invoice-exports'))->toBe([]);
});

test('a cancelled or failed build is not carried on by a job that was already queued', function () {
    Storage::fake('local');
    $admin = $this->adminUser();
    [$trip, $c] = bgTripWith($this, $admin, ['Adi']);

    foreach (['cancelled', 'failed', 'done'] as $status) {
        $export = InvoiceExport::create([
            'trip_id' => $trip->id, 'created_by' => $admin->id, 'status' => $status,
            'customer_ids' => [$c['Adi']->id], 'total_customers' => 1, 'customers_per_part' => 50, 'total_parts' => 1,
        ]);

        (new GenerateInvoiceExportPartJob($export->id, 0))->handle(app(CombinedInvoiceService::class), app(\App\Services\InvoicePdfRenderer::class));

        expect($export->fresh()->status)->toBe($status);
        expect(Storage::disk('local')->exists($export->directory()))->toBeFalse();
    }
})->skip(fn () => bgNoPdf(), 'dompdf is not installed (composer require dompdf/dompdf)');

test('if the job crashes the build is marked failed with the reason, never left running', function () {
    $admin = $this->adminUser();
    [$trip, $c] = bgTripWith($this, $admin, ['Adi']);
    $export = InvoiceExport::create([
        'trip_id' => $trip->id, 'created_by' => $admin->id, 'status' => 'running',
        'customer_ids' => [$c['Adi']->id], 'total_customers' => 1, 'customers_per_part' => 50, 'total_parts' => 1,
    ]);

    (new GenerateInvoiceExportPartJob($export->id, 0))->failed(new RuntimeException('The disk is full'));

    expect($export->fresh()->status)->toBe('failed');
    expect($export->fresh()->error_message)->toContain('The disk is full');
});

test('a late failure report never overwrites a build that already finished', function () {
    $admin = $this->adminUser();
    [$trip, $c] = bgTripWith($this, $admin, ['Adi']);
    $export = InvoiceExport::create([
        'trip_id' => $trip->id, 'created_by' => $admin->id, 'status' => 'done',
        'customer_ids' => [$c['Adi']->id], 'total_customers' => 1, 'customers_per_part' => 50, 'total_parts' => 1,
    ]);

    (new GenerateInvoiceExportPartJob($export->id, 0))->failed(new RuntimeException('late'));

    expect($export->fresh()->status)->toBe('done');
});

test('if every order vanished while it ran, the build says so instead of handing over an empty ZIP', function () {
    Storage::fake('local');
    $admin = $this->adminUser();
    $trip  = $this->openTrip();
    $ghost = bgCustomer($this, $admin, 'Ghost');             // a customer with no orders in this trip

    $export = InvoiceExport::create([
        'trip_id' => $trip->id, 'created_by' => $admin->id, 'status' => 'queued',
        'customer_ids' => [$ghost->id], 'total_customers' => 1, 'customers_per_part' => 50, 'total_parts' => 1,
    ]);

    GenerateInvoiceExportPartJob::dispatch($export->id, 0);

    expect($export->fresh()->status)->toBe('failed');
    expect($export->fresh()->error_message)->toContain('No invoices were produced');
})->skip(fn () => bgNoPdf(), 'dompdf is not installed (composer require dompdf/dompdf)');

// ── Downloading ─────────────────────────────────────────────────────

test('a finished build downloads as a ZIP and the download is logged', function () {
    Storage::fake('local');
    $admin = $this->adminUser();
    [$trip, $c] = bgTripWith($this, $admin, ['Adi']);

    $export = InvoiceExport::create([
        'trip_id' => $trip->id, 'created_by' => $admin->id, 'status' => 'done',
        'customer_ids' => [$c['Adi']->id], 'total_customers' => 1, 'customers_per_part' => 50, 'total_parts' => 1,
        'file_path' => 'invoice-exports/1/invoices_test.zip',
    ]);
    Storage::disk('local')->put($export->file_path, 'zip-bytes');

    $this->actingAs($admin)->get(route('invoice-exports.download', $export))->assertOk()->assertDownload('invoices_test.zip');
    expect(ActivityLog::where('action', 'invoice.trip_pdf_downloaded')->count())->toBe(1);
});

test('a build that is not finished, or whose file is gone, cannot be downloaded', function () {
    Storage::fake('local');
    $admin = $this->adminUser();
    [$trip, $c] = bgTripWith($this, $admin, ['Adi']);
    $base = ['trip_id' => $trip->id, 'created_by' => $admin->id, 'customer_ids' => [$c['Adi']->id], 'total_customers' => 1, 'customers_per_part' => 50, 'total_parts' => 1];

    $running = InvoiceExport::create($base + ['status' => 'running']);
    $missing = InvoiceExport::create($base + ['status' => 'done', 'file_path' => 'invoice-exports/9/gone.zip']);

    $this->actingAs($admin)->get(route('invoice-exports.download', $running))->assertNotFound();
    $this->actingAs($admin)->get(route('invoice-exports.download', $missing))->assertNotFound();
});

// ── Who may touch what ──────────────────────────────────────────────

test('staff without export permission cannot open the page or start a build', function () {
    Queue::fake();
    $admin = $this->adminUser();
    $staff = $this->staffUser(['permissions' => ['orders.export' => false]]);
    [$trip] = bgTripWith($this, $admin, ['Adi']);

    $this->actingAs($staff)->get(route('invoice-exports.index'))->assertForbidden();
    $this->actingAs($staff)->post(route('trips.invoice-exports.store', $trip))->assertForbidden();
    expect(InvoiceExport::count())->toBe(0);
});

test('staff who only see their own data cannot download or remove someone else\'s build', function () {
    Storage::fake('local');
    $admin = $this->adminUser();
    $staff = User::factory()->ownDataOnly()->create(['permissions' => ['orders.export' => true]]);
    [$trip, $c] = bgTripWith($this, $admin, ['Adi']);

    $export = InvoiceExport::create([
        'trip_id' => $trip->id, 'created_by' => $admin->id, 'status' => 'done',
        'customer_ids' => [$c['Adi']->id], 'total_customers' => 1, 'customers_per_part' => 50, 'total_parts' => 1,
        'file_path' => 'invoice-exports/1/invoices_test.zip',
    ]);
    Storage::disk('local')->put($export->file_path, 'zip-bytes');

    $this->actingAs($staff)->get(route('invoice-exports.download', $export))->assertForbidden();
    $this->actingAs($staff)->delete(route('invoice-exports.destroy', $export))->assertForbidden();
    expect(InvoiceExport::find($export->id))->not->toBeNull();

    // and they don't even see it listed (the list shows the trip's name)
    $this->actingAs($staff)->get(route('invoice-exports.index'))->assertOk()->assertDontSeeText($trip->name);
    $this->actingAs($admin)->get(route('invoice-exports.index'))->assertOk()->assertSeeText($trip->name);
});

// ── The page ────────────────────────────────────────────────────────

test('arriving from a too-big trip, the page offers to build it in the background', function () {
    $admin = $this->adminUser();
    [$trip] = bgTripWith($this, $admin, ['Adi', 'Budi', 'Citra']);

    $page = $this->actingAs($admin)->get(route('invoice-exports.index', ['trip' => $trip->id]))->assertOk();

    $page->assertSeeText('3 customers')->assertSeeText('Start building');
    expect($page->getContent())->toContain(route('trips.invoice-exports.store', $trip));
});

test('without a trip chosen there is no offer, and an empty list says what this page is for', function () {
    $this->actingAs($this->adminUser())->get(route('invoice-exports.index'))
        ->assertOk()->assertDontSeeText('Start building')->assertSeeText('Nothing here yet');
});

test('the page shows progress, refreshes itself only while something is running, and offers the ZIP when done', function () {
    $admin = $this->adminUser();
    [$trip, $c] = bgTripWith($this, $admin, ['Adi']);
    $base = ['trip_id' => $trip->id, 'created_by' => $admin->id, 'customer_ids' => [$c['Adi']->id], 'total_customers' => 4000, 'customers_per_part' => 50, 'total_parts' => 80];

    $done = InvoiceExport::create($base + ['status' => 'done', 'parts_done' => 80, 'file_path' => 'invoice-exports/1/x.zip', 'file_size' => 5 * 1048576]);

    // Only a finished build: no auto-refresh, and a download button.
    $page = $this->actingAs($admin)->get(route('invoice-exports.index'))->assertOk();
    $page->assertSeeText('Download ZIP')->assertSeeText('100%')->assertSeeText('5.0 MB');
    expect($page->getContent())->not->toContain('window.location.reload');

    // Add one in progress: now the page keeps itself up to date.
    InvoiceExport::create($base + ['status' => 'running', 'parts_done' => 20]);
    $page = $this->actingAs($admin)->get(route('invoice-exports.index'))->assertOk();
    $page->assertSeeText('25%')->assertSeeText('20 / 80 parts');
    expect($page->getContent())->toContain('window.location.reload');
});

test('a finished build says how long it took, so the real speed of the server is visible', function () {
    $admin = $this->adminUser();
    [$trip, $c] = bgTripWith($this, $admin, ['Adi']);

    InvoiceExport::create([
        'trip_id' => $trip->id, 'created_by' => $admin->id, 'status' => 'done',
        'customer_ids' => [$c['Adi']->id], 'total_customers' => 1, 'customers_per_part' => 25, 'total_parts' => 1, 'parts_done' => 1,
        'file_path' => 'invoice-exports/1/x.zip', 'file_size' => 1048576,
        'started_at' => now()->subMinutes(14), 'finished_at' => now(),
    ]);

    $this->actingAs($admin)->get(route('invoice-exports.index'))->assertOk()->assertSeeText('took 14 minutes');
});

test('a build stuck waiting for the worker says so, and a failed one shows why', function () {
    $admin = $this->adminUser();
    [$trip, $c] = bgTripWith($this, $admin, ['Adi']);
    $base = ['trip_id' => $trip->id, 'created_by' => $admin->id, 'customer_ids' => [$c['Adi']->id], 'total_customers' => 1, 'customers_per_part' => 50, 'total_parts' => 1];

    $waiting = InvoiceExport::create($base + ['status' => 'queued']);
    DB::table('invoice_exports')->where('id', $waiting->id)->update(['created_at' => now()->subMinutes(10)]);
    InvoiceExport::create($base + ['status' => 'failed', 'error_message' => 'The disk is full']);

    $this->actingAs($admin)->get(route('invoice-exports.index'))->assertOk()
        ->assertSeeText('Still waiting for the background worker')
        ->assertSeeText('The disk is full');
});

test('the Orders export menu links to the progress page', function () {
    $this->actingAs($this->adminUser())->get(route('orders.index'))->assertOk()->assertSeeText('Invoice PDF builds');
});

// ── Cleanup ─────────────────────────────────────────────────────────

test('the nightly cleanup removes old builds and their files, but keeps recent ones', function () {
    Storage::fake('local');
    $admin = $this->adminUser();
    [$trip, $c] = bgTripWith($this, $admin, ['Adi']);
    $base = ['trip_id' => $trip->id, 'created_by' => $admin->id, 'status' => 'done', 'customer_ids' => [$c['Adi']->id], 'total_customers' => 1, 'customers_per_part' => 50, 'total_parts' => 1];

    $old    = InvoiceExport::create($base);
    $recent = InvoiceExport::create($base);
    foreach ([$old, $recent] as $e) {
        Storage::disk('local')->put($e->directory() . '/invoices.zip', 'x');
    }
    DB::table('invoice_exports')->where('id', $old->id)->update(['created_at' => now()->subDays(10)]);

    Artisan::call('invoices:prune');

    expect(InvoiceExport::find($old->id))->toBeNull();
    expect(Storage::disk('local')->exists($old->directory()))->toBeFalse();
    expect(InvoiceExport::find($recent->id))->not->toBeNull();
    expect(Storage::disk('local')->exists($recent->directory() . '/invoices.zip'))->toBeTrue();
});

test('the cleanup stops a build that has made no progress for a day, and clears folders with no record', function () {
    Storage::fake('local');
    $admin = $this->adminUser();
    [$trip, $c] = bgTripWith($this, $admin, ['Adi']);

    $stuck = InvoiceExport::create(['trip_id' => $trip->id, 'created_by' => $admin->id, 'status' => 'running',
        'customer_ids' => [$c['Adi']->id], 'total_customers' => 1, 'customers_per_part' => 50, 'total_parts' => 1]);
    DB::table('invoice_exports')->where('id', $stuck->id)->update(['updated_at' => now()->subDays(2)]);

    Storage::disk('local')->put('invoice-exports/987654/leftover.pdf', 'x');   // no build owns this folder

    Artisan::call('invoices:prune');

    expect($stuck->fresh()->status)->toBe('failed');
    expect($stuck->fresh()->error_message)->toContain('no progress');
    expect(Storage::disk('local')->exists('invoice-exports/987654'))->toBeFalse();
});
