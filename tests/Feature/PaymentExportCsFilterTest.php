<?php

use App\Models\ActivityLog;
use App\Models\CsAgent;
use App\Models\Order;
use App\Models\Payment;

/*
 * The payments Excel export has the same optional CS checklist as the orders export.
 *
 *  - Payment Log sheet: the payments made on orders handled by a ticked CS.
 *  - Outstanding Balances sheet: the customers who have at least one order handled by a ticked
 *    CS — and their figures still add up ALL their orders in the trip (a balance worked out from
 *    only some of a customer's orders would be wrong).
 *  - Nothing ticked = everything, exactly as before.
 */

/** Rows of one sheet (1 = Outstanding Balances, 2 = Payment Log) of the downloaded .xlsx, as arrays of cell values. */
function pxSheet($response, int $n): array
{
    $zip = new ZipArchive();
    $zip->open($response->baseResponse->getFile()->getPathname());

    preg_match_all('#<si><t>(.*?)</t></si>#s', (string) $zip->getFromName('xl/sharedStrings.xml'), $m);
    $strings = array_map(fn ($s) => html_entity_decode($s, ENT_QUOTES | ENT_XML1), $m[1]);

    $xml = (string) $zip->getFromName("xl/worksheets/sheet{$n}.xml");
    $zip->close();

    $rows = [];
    preg_match_all('#<row[^>]*>(.*?)</row>#s', $xml, $rowMatches);
    foreach ($rowMatches[1] as $rowXml) {
        preg_match_all('#<c r="[A-Z]+\d+"(?: t="(s)")?(?:/>|><v>(.*?)</v></c>)#', $rowXml, $cells, PREG_SET_ORDER);
        $rows[] = array_map(function ($c) use ($strings) {
            $v = $c[2] ?? '';
            if (($c[1] ?? '') === 's') return $strings[(int) $v] ?? '';
            return $v === '' ? '' : (float) $v;
        }, $cells);
    }
    return $rows;
}

function pxCustomer($test, $admin, string $name)
{
    $customer = $test->customer($admin);
    $customer->update(['name' => $name]);
    return $customer->fresh();
}

/** An unpaid 500.000 order with one payment of $paid on it. */
function pxOrderWithPayment($trip, $customer, $admin, ?CsAgent $cs, int $paid): Order
{
    $order = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
        'cs_agent_id' => $cs?->id, 'shipping_area_id' => null,
        'subtotal' => 500000, 'total_amount' => 500000, 'deposit_paid' => $paid, 'payment_status' => 'partial',
    ]);
    Payment::factory()->create([
        'order_id' => $order->id, 'amount' => $paid, 'type' => 'deposit',
        'paid_at' => now(), 'voided_at' => null, 'verification_status' => 'verified',
    ]);
    return $order;
}

/**
 * Adi: one order by Rina (paid 100.000).
 * Budi: two orders — one by Rina (paid 200.000), one by Dewi (paid 300.000).
 * Citra: one order by Dewi (paid 400.000).
 * Eka: one order with no CS (paid 450.000 — still owes 50.000, so she is on the balances sheet).
 */
function pxScenario($test): array
{
    $admin = $test->adminUser();
    $trip  = $test->openTrip();
    $rina  = CsAgent::factory()->create(['name' => 'Rina']);
    $dewi  = CsAgent::factory()->create(['name' => 'Dewi']);

    pxOrderWithPayment($trip, pxCustomer($test, $admin, 'Adi'),   $admin, $rina, 100000);
    $budi = pxCustomer($test, $admin, 'Budi');
    pxOrderWithPayment($trip, $budi,                              $admin, $rina, 200000);
    pxOrderWithPayment($trip, $budi,                              $admin, $dewi, 300000);
    pxOrderWithPayment($trip, pxCustomer($test, $admin, 'Citra'), $admin, $dewi, 400000);
    pxOrderWithPayment($trip, pxCustomer($test, $admin, 'Eka'),   $admin, null,  450000);

    return compact('admin', 'trip', 'rina', 'dewi');
}

function pxExport($test, array $s, array $cs = [])
{
    $query = ['trip_id' => $s['trip']->id];
    if ($cs !== []) $query['cs_agent_ids'] = $cs;
    return $test->actingAs($s['admin'])->get(route('payments.export', $query));
}

/** The data rows (header dropped) of a sheet: customer names for Balances, payment amounts for the Log. */
function pxBalanceCustomers(array $rows): array { return collect(array_slice($rows, 1))->pluck(0)->sort()->values()->all(); }
function pxLogAmounts(array $rows): array       { return collect(array_slice($rows, 1))->pluck(4)->map(fn ($v) => (int) $v)->sort()->values()->all(); }

test('nothing ticked exports everything, as before', function () {
    $s = pxScenario($this);
    $r = pxExport($this, $s);
    $r->assertOk();

    expect(pxBalanceCustomers(pxSheet($r, 1)))->toBe(['Adi', 'Budi', 'Citra', 'Eka']);
    expect(pxLogAmounts(pxSheet($r, 2)))->toBe([100000, 200000, 300000, 400000, 450000]);
    expect($r->headers->get('Content-Disposition'))->not->toContain('filtered-by-CS');
});

test('one CS: the log has the payments on their orders, the balances list their customers', function () {
    $s = pxScenario($this);
    $r = pxExport($this, $s, [(string) $s['rina']->id]);

    expect(pxLogAmounts(pxSheet($r, 2)))->toBe([100000, 200000]);                 // Adi's and Budi's Rina order — not Budi's Dewi order
    expect(pxBalanceCustomers(pxSheet($r, 1)))->toBe(['Adi', 'Budi']);
    expect($r->headers->get('Content-Disposition'))->toContain('filtered-by-CS');
});

test('a customer served by the ticked CS still has their balance over ALL their orders', function () {
    $s = pxScenario($this);
    $r = pxExport($this, $s, [(string) $s['rina']->id]);

    // Balances columns: Customer, Phone, Type, Orders, Total Ordered, Total Paid, Balance Due
    $budi = collect(array_slice(pxSheet($r, 1), 1))->firstWhere(0, 'Budi');
    expect($budi[3])->toBe(2.0);          // both orders, not just Rina's
    expect($budi[4])->toBe(1000000.0);
    expect($budi[5])->toBe(500000.0);     // 200.000 + 300.000
    expect($budi[6])->toBe(500000.0);
});

test('several CS together; a customer served by two of them is listed once', function () {
    $s = pxScenario($this);
    $r = pxExport($this, $s, [(string) $s['rina']->id, (string) $s['dewi']->id]);

    expect(pxBalanceCustomers(pxSheet($r, 1)))->toBe(['Adi', 'Budi', 'Citra']);
    expect(pxLogAmounts(pxSheet($r, 2)))->toBe([100000, 200000, 300000, 400000]);
});

test('"No CS agent assigned" picks the orders with no CS', function () {
    $s = pxScenario($this);
    $r = pxExport($this, $s, ['none']);

    expect(pxBalanceCustomers(pxSheet($r, 1)))->toBe(['Eka']);
    expect(pxLogAmounts(pxSheet($r, 2)))->toBe([450000]);
});

test('garbage in the checklist exports no data rows rather than everything', function () {
    $s = pxScenario($this);
    $r = pxExport($this, $s, ['abc']);

    expect(pxBalanceCustomers(pxSheet($r, 1)))->toBe([]);
    expect(pxLogAmounts(pxSheet($r, 2)))->toBe([]);
});

test('the payments page offers the CS pop-up for the export', function () {
    $s = pxScenario($this);

    $this->actingAs($s['admin'])->get(route('payments.index', ['trip_id' => $s['trip']->id, 'tab' => 'log']))
        ->assertOk()
        ->assertSee('exportPaymentsModal', false)
        ->assertSee('Rina')
        ->assertSee('No CS agent assigned');
});

// ── The Activity Log ────────────────────────────────────────────────

test('exporting payments leaves a line in the Activity Log, with the CS when one is ticked', function () {
    $s = pxScenario($this);

    pxExport($this, $s)->assertOk();
    $all = ActivityLog::where('action', 'payment.exported')->latest('id')->first();
    expect($all)->not->toBeNull();
    expect($all->user_id)->toBe($s['admin']->id);
    expect($all->description)->toContain('5 payment(s)')->toContain('4 customer(s) owing')->not->toContain('CS:');

    pxExport($this, $s, [(string) $s['rina']->id])->assertOk();
    $rina = ActivityLog::where('action', 'payment.exported')->latest('id')->first();
    expect($rina->description)->toContain('2 payment(s)')->toContain('2 customer(s) owing')->toContain('(CS: Rina)');
});

test('exporting orders leaves a line in the Activity Log, with the CS when one is ticked', function () {
    $s = pxScenario($this);

    $this->actingAs($s['admin'])->get(route('orders.export', ['trip_id' => $s['trip']->id]))->assertOk();
    $all = ActivityLog::where('action', 'report.orders_exported')->latest('id')->first();
    expect($all)->not->toBeNull();
    expect($all->description)->toContain($s['trip']->name)->toContain('5 order(s)')->not->toContain('CS:');

    $this->actingAs($s['admin'])
        ->get(route('orders.export', ['trip_id' => $s['trip']->id, 'cs_agent_ids' => [$s['dewi']->id, 'none']]))->assertOk();
    $some = ActivityLog::where('action', 'report.orders_exported')->latest('id')->first();
    expect($some->description)->toContain('3 order(s)')->toContain('Dewi')->toContain('No CS agent assigned');
});

test('the Activity Log can be filtered to the two new export actions', function () {
    $s = pxScenario($this);

    $this->actingAs($s['admin'])->get(route('activity-logs.index'))
        ->assertOk()->assertSee('Payments exported to Excel')->assertSee('Orders exported to Excel');
});

// ── Payment Type column ─────────────────────────────────────────────

test('the Payment Log has a Payment Type column right after Amount, in the words used when recording', function () {
    $s = pxScenario($this);
    $order = Order::where('trip_id', $s['trip']->id)->first();
    Payment::factory()->create(['order_id' => $order->id, 'amount' => 111, 'type' => 'full',    'paid_at' => now(), 'voided_at' => null, 'verification_status' => 'verified']);
    Payment::factory()->create(['order_id' => $order->id, 'amount' => 222, 'type' => 'partial', 'paid_at' => now(), 'voided_at' => null, 'verification_status' => 'verified']);

    $rows = pxSheet(pxExport($this, $s), 2);

    expect($rows[0])->toBe(['Date', 'Customer', 'Phone', 'Order Number', 'Amount (Rp)', 'Payment Type', 'Method', 'Reference', 'Notes', 'Recorded By', 'Voided']);

    $byAmount = collect(array_slice($rows, 1))->mapWithKeys(fn ($r) => [(int) $r[4] => $r[5]]);
    expect($byAmount[111])->toBe('Full Payment');   // not the stored word "Full"
    expect($byAmount[222])->toBe('Partial');
    expect($byAmount[100000])->toBe('Deposit');     // the scenario's own payments are deposits
});
