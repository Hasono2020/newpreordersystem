<?php

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Services\CreditReallocationService;
use App\Services\PromoService;

/*
 * Reported: a customer with two orders paid MORE than the two orders
 * together cost, but because the money sat on the wrong orders (a big
 * deposit on the small order, a small deposit on the big one) the big order
 * kept reading "Partially Paid" until staff refunded the overpayment.
 *
 * Expected: if what the customer has paid covers what they owe in total,
 * every order reads Fully Paid — the leftover is a separate refund matter.
 *
 * The credit-moving service already existed but only ran after price syncs.
 * It now runs after anything that changes what an order has paid or owes,
 * and also UNDOES a move when the money behind it is later voided or
 * disputed. Only VERIFIED money is ever moved.
 */

function twoOrders(object $test, $admin, int $bigTotal = 150000, int $smallTotal = 30000): array
{
    $trip     = $test->openTrip();
    $customer = $test->customer($admin);
    $big = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'total_amount' => $bigTotal,
        'order_number' => null, 'created_at' => now()->subHour(), 'ordered_at' => now()->subHour(),
    ]);
    $small = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'total_amount' => $smallTotal,
        'order_number' => null,
    ]);
    return [$trip, $customer, $big, $small];
}

function recordPayment(object $test, $admin, Order $order, int $amount, bool $verify = true): Payment
{
    $test->actingAs($admin)->post(route('orders.payments.add', $order), [
        'amount' => $amount, 'type' => 'deposit', 'paid_at' => now()->format('Y-m-d'),
    ])->assertSessionDoesntHaveErrors();

    $payment = Payment::where('order_id', $order->id)->where('type', 'deposit')->latest('id')->first();

    // Only verified money may be moved between orders or refunded, so the
    // money in these tests has to be confirmed first — just as staff would
    // confirm the transfer against the bank statement.
    if ($verify) {
        $test->actingAs($admin)->post(route('payments.verify', $payment));
    }
    return $payment;
}

test('when combined payments cover both orders, both show Fully Paid — before any refund', function () {
    $admin = $this->adminUser();
    [$trip, $customer, $big, $small] = twoOrders($this, $admin);

    recordPayment($this, $admin, $big, 20000);    // small deposit on the big order
    recordPayment($this, $admin, $small, 180000); // big deposit on the small order

    // Owed 180,000 in total, paid 200,000 — so both are covered.
    expect($big->fresh()->payment_status)->toBe('paid');
    expect($small->fresh()->payment_status)->toBe('paid');
    expect((float) $big->fresh()->deposit_paid)->toBe(150000.0);   // 20,000 own + 130,000 moved across
    expect((float) $small->fresh()->deposit_paid)->toBe(50000.0);  // 180,000 - 130,000 moved; 20,000 genuine surplus
    expect(ActivityLog::where('action', 'payment.auto_reallocated')->count())->toBe(1);
});

test('the order the payments were recorded in does not matter', function () {
    $admin = $this->adminUser();
    [$trip, $customer, $big, $small] = twoOrders($this, $admin);

    recordPayment($this, $admin, $small, 180000); // the oversized deposit first
    expect($big->fresh()->payment_status)->toBe('paid'); // already covered by the small order's surplus

    recordPayment($this, $admin, $big, 20000);

    expect($big->fresh()->payment_status)->toBe('paid');
    expect($small->fresh()->payment_status)->toBe('paid');
    expect((float) $big->fresh()->deposit_paid + (float) $small->fresh()->deposit_paid)->toBe(200000.0); // nothing lost
});

test('voiding the deposit that funded a move reverses the move, so the other order is not left falsely paid', function () {
    $admin = $this->adminUser();
    [$trip, $customer, $big, $small] = twoOrders($this, $admin);
    recordPayment($this, $admin, $big, 20000);
    recordPayment($this, $admin, $small, 180000);
    expect($big->fresh()->payment_status)->toBe('paid'); // sanity: it was covered

    $deposit = Payment::where('order_id', $small->id)->where('type', 'deposit')->first();
    $this->actingAs($admin)->post(route('payments.void', $deposit), ['void_reason' => 'Wrong amount']);

    // The 180,000 is gone, so the 130,000 that had been moved onto the big
    // order has nothing behind it any more. Only its own 20,000 is real.
    expect($big->fresh()->payment_status)->toBe('partial');
    expect((float) $big->fresh()->deposit_paid)->toBe(20000.0);
    expect($small->fresh()->payment_status)->toBe('unpaid');
    expect(Payment::where('method', 'reallocation')->whereNull('voided_at')->count())->toBe(0);
    expect(ActivityLog::where('action', 'payment.reallocation_reversed')->count())->toBe(1);
});

test('refunding the leftover overpayment with a Credit Note keeps both orders Fully Paid', function () {
    $admin = $this->adminUser();
    [$trip, $customer, $big, $small] = twoOrders($this, $admin);
    recordPayment($this, $admin, $big, 20000);
    recordPayment($this, $admin, $small, 180000); // 20,000 genuinely over

    // The 20,000 surplus is on the small order (paid 50,000 vs owed 30,000).
    $this->actingAs($admin)->post(route('orders.sales-adjustments.store', $small), [
        'type' => 'credit_note', 'amount' => 20000, 'reason' => 'refund overpayment',
    ])->assertSessionDoesntHaveErrors();

    expect($big->fresh()->payment_status)->toBe('paid');
    expect($small->fresh()->payment_status)->toBe('paid');
    // Paid now equals owed across the customer: 150,000 + 30,000.
    expect((float) $big->fresh()->deposit_paid + (float) $small->fresh()->deposit_paid)->toBe(180000.0);
});

test('rebalancing is idempotent — running it again changes nothing', function () {
    $admin = $this->adminUser();
    [$trip, $customer, $big, $small] = twoOrders($this, $admin);
    recordPayment($this, $admin, $big, 20000);
    recordPayment($this, $admin, $small, 180000);

    $before = Payment::count();
    app(CreditReallocationService::class)->reconcile($customer->id, $trip->id);
    app(CreditReallocationService::class)->reconcile($customer->id, $trip->id);

    expect(Payment::count())->toBe($before);
    expect(Payment::whereNotNull('voided_at')->count())->toBe(0);
});

test('an item change that moves totals also rebalances credit between the customer\'s orders', function () {
    $admin    = $this->adminUser();
    $trip     = $this->openTrip();
    $customer = $this->customer($admin);
    $customer->update(['default_shipping_area_id' => null]);
    $p1 = Product::create(['trip_id' => $trip->id, 'product_code' => 'XC01', 'price' => 100000, 'weight_gram' => 100, 'status' => 'active']);
    $p2 = Product::create(['trip_id' => $trip->id, 'product_code' => 'XC02', 'price' => 80000,  'weight_gram' => 100, 'status' => 'active']);

    $a = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'shipping_area_id' => null, 'order_number' => null, 'created_at' => now()->subHour()]);
    OrderItem::create(['order_id' => $a->id, 'product_id' => $p1->id, 'quantity' => 1, 'unit_price' => 100000, 'line_total' => 100000, 'status' => 'pending']);
    $b = Order::factory()->create(['trip_id' => $trip->id, 'customer_id' => $customer->id, 'shipping_area_id' => null, 'order_number' => null]);
    OrderItem::create(['order_id' => $b->id, 'product_id' => $p2->id, 'quantity' => 1, 'unit_price' => 80000, 'line_total' => 80000, 'status' => 'pending']);

    Payment::create(['order_id' => $a->id, 'amount' => 190000, 'type' => 'deposit', 'method' => 'Transfer', 'paid_at' => now(), 'recorded_by' => $admin->id, 'verification_status' => 'verified']);

    app(PromoService::class)->recalcCustomerShipping($customer->id, $trip->id);

    expect($b->fresh()->payment_status)->toBe('paid');
    expect((float) $b->fresh()->deposit_paid)->toBe(80000.0);
    expect((float) $a->fresh()->deposit_paid)->toBe(110000.0);
});

test('when the customer has NOT paid enough in total, the surplus still goes across but the short order stays partially paid', function () {
    $admin = $this->adminUser();
    [$trip, $customer, $big, $small] = twoOrders($this, $admin);

    recordPayment($this, $admin, $big, 20000);
    recordPayment($this, $admin, $small, 60000); // paid 80,000 in total vs 180,000 owed

    expect($small->fresh()->payment_status)->toBe('paid');           // its own 30,000 is covered...
    expect($big->fresh()->payment_status)->toBe('partial');          // ...and its 30,000 surplus goes to the big one,
    expect((float) $big->fresh()->deposit_paid)->toBe(50000.0);      // which still owes 100,000 more.
    expect((float) $small->fresh()->deposit_paid)->toBe(30000.0);
});

test('an unverified deposit does NOT cover the other order — only once it has been verified', function () {
    $admin = $this->adminUser();
    [$trip, $customer, $big, $small] = twoOrders($this, $admin);

    // A customer says they transferred 180,000; staff typed it in; nobody has
    // confirmed it arrived yet.
    $deposit = recordPayment($this, $admin, $small, 180000, verify: false);

    expect($big->fresh()->payment_status)->toBe('unpaid');       // nothing moved across
    expect((float) $small->fresh()->deposit_paid)->toBe(180000.0);
    expect(Payment::where('method', 'reallocation')->count())->toBe(0);

    // Staff confirm it against the bank — now it can cover the other order.
    $this->actingAs($admin)->post(route('payments.verify', $deposit));

    expect($big->fresh()->payment_status)->toBe('paid');
    expect((float) $small->fresh()->deposit_paid)->toBe(30000.0);
});

test('disputing the deposit that funded a move pulls the moved credit back', function () {
    $admin = $this->adminUser();
    [$trip, $customer, $big, $small] = twoOrders($this, $admin);
    recordPayment($this, $admin, $big, 20000);
    $deposit = recordPayment($this, $admin, $small, 180000);
    expect($big->fresh()->payment_status)->toBe('paid'); // sanity

    $this->actingAs($admin)->post(route('payments.dispute', $deposit), ['dispute_note' => 'Not on the bank statement']);

    expect($big->fresh()->payment_status)->toBe('partial');
    expect((float) $big->fresh()->deposit_paid)->toBe(20000.0);
    expect(Payment::where('method', 'reallocation')->whereNull('voided_at')->count())->toBe(0);
});
