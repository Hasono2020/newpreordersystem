<?php

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ShippingArea;
use App\Models\Trip;
use App\Services\CreditReallocationService;

/*
 * Tests for CreditReallocationService — automatically moves overpayment
 * credit from one order to cover a shortfall on another order for the
 * SAME customer within the SAME trip, after a price sync creates that
 * split. Every reallocation must leave a visible trail: a 'refund'-type
 * deduction on the overpaid order and a 'partial'-type addition on the
 * underpaid one, cross-referencing each other — never a silent edit of
 * deposit_paid with no explanation.
 */

test('reallocates credit from an overpaid order to cover an underpaid order for the same customer', function () {
    $trip = Trip::factory()->open()->create(['created_by' => $this->adminUser()->id]);
    $admin = $this->adminUser();
    $area  = $this->shippingArea();
    $customer = $this->customer($admin);

    $overpaid = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
        'shipping_area_id' => $area->id, 'subtotal' => 400000, 'total_amount' => 400000,
        'deposit_paid' => 500000, 'payment_status' => 'paid', 'ordered_at' => now()->subMinutes(10),
    ]);
    Payment::factory()->create(['order_id' => $overpaid->id, 'amount' => 500000, 'type' => 'deposit', 'paid_at' => now(), 'voided_at' => null]);

    $underpaid = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
        'shipping_area_id' => $area->id, 'subtotal' => 1045000, 'total_amount' => 1045000,
        'deposit_paid' => 1015000, 'payment_status' => 'partial', 'ordered_at' => now(),
    ]);
    Payment::factory()->create(['order_id' => $underpaid->id, 'amount' => 1015000, 'type' => 'deposit', 'paid_at' => now(), 'voided_at' => null]);

    app(CreditReallocationService::class)->reallocate($customer->id, $trip->id);

    $freshOverpaid  = $overpaid->fresh();
    $freshUnderpaid = $underpaid->fresh();

    expect((float) $freshOverpaid->deposit_paid)->toBe(470000.0) // 500000 - 30000 transferred (only what was needed)
        ->and($freshOverpaid->payment_status)->toBe('paid') // still overpaid by 70000, but that's fine — not this test's concern
        ->and((float) $freshUnderpaid->deposit_paid)->toBe(1045000.0)
        ->and($freshUnderpaid->payment_status)->toBe('paid');
});

test('the reallocation leaves a visible, cross-referenced trail in Payment History', function () {
    $trip = Trip::factory()->open()->create(['created_by' => $this->adminUser()->id]);
    $admin = $this->adminUser();
    $area  = $this->shippingArea();
    $customer = $this->customer($admin);

    // Excess (100,000) exactly matches the shortfall (100,000) so the
    // whole transfer amount is unambiguous.
    $overpaid = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
        'shipping_area_id' => $area->id, 'subtotal' => 400000, 'total_amount' => 400000,
        'deposit_paid' => 500000, 'payment_status' => 'paid',
    ]);
    Payment::factory()->create(['order_id' => $overpaid->id, 'amount' => 500000, 'type' => 'deposit', 'paid_at' => now(), 'voided_at' => null]);

    $underpaid = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
        'shipping_area_id' => $area->id, 'subtotal' => 500000, 'total_amount' => 500000,
        'deposit_paid' => 400000, 'payment_status' => 'partial',
    ]);
    Payment::factory()->create(['order_id' => $underpaid->id, 'amount' => 400000, 'type' => 'deposit', 'paid_at' => now(), 'voided_at' => null]);

    app(CreditReallocationService::class)->reallocate($customer->id, $trip->id);

    $deduction = Payment::where('order_id', $overpaid->id)->where('type', 'refund')->first();
    $addition  = Payment::where('order_id', $underpaid->id)->where('type', 'partial')->where('method', 'reallocation')->first();

    expect($deduction)->not->toBeNull()
        ->and((float) $deduction->amount)->toBe(100000.0)
        ->and($deduction->reference)->toContain($underpaid->order_number)
        ->and($deduction->verification_status)->toBe('verified')
        ->and($addition)->not->toBeNull()
        ->and((float) $addition->amount)->toBe(100000.0)
        ->and($addition->reference)->toContain($overpaid->order_number)
        ->and($addition->batch_id)->toBe($deduction->batch_id);

    expect((float) $overpaid->fresh()->deposit_paid)->toBe(400000.0)
        ->and((float) $underpaid->fresh()->deposit_paid)->toBe(500000.0);
});

test('does nothing when there is no overpaid order to draw from', function () {
    $trip  = Trip::factory()->open()->create(['created_by' => $this->adminUser()->id]);
    $admin = $this->adminUser();
    $area  = $this->shippingArea();
    $customer = $this->customer($admin);

    $underpaid = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
        'shipping_area_id' => $area->id, 'subtotal' => 500000, 'total_amount' => 500000,
        'deposit_paid' => 300000, 'payment_status' => 'partial',
    ]);
    Payment::factory()->create(['order_id' => $underpaid->id, 'amount' => 300000, 'type' => 'deposit', 'paid_at' => now(), 'voided_at' => null]);

    $paymentCountBefore = Payment::count();

    app(CreditReallocationService::class)->reallocate($customer->id, $trip->id);

    expect(Payment::count())->toBe($paymentCountBefore)
        ->and((float) $underpaid->fresh()->deposit_paid)->toBe(300000.0);
});

test('multiple underpaid orders are covered oldest-first (FIFO)', function () {
    $trip = Trip::factory()->open()->create(['created_by' => $this->adminUser()->id]);
    $admin = $this->adminUser();
    $area  = $this->shippingArea();
    $customer = $this->customer($admin);

    $overpaid = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
        'shipping_area_id' => $area->id, 'subtotal' => 100000, 'total_amount' => 100000,
        'deposit_paid' => 150000, 'payment_status' => 'paid',
    ]);
    Payment::factory()->create(['order_id' => $overpaid->id, 'amount' => 150000, 'type' => 'deposit', 'paid_at' => now(), 'voided_at' => null]);

    // Older shortfall: needs 30,000
    $older = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
        'shipping_area_id' => $area->id, 'subtotal' => 100000, 'total_amount' => 100000,
        'deposit_paid' => 70000, 'payment_status' => 'partial', 'ordered_at' => now()->subMinutes(20),
    ]);
    Payment::factory()->create(['order_id' => $older->id, 'amount' => 70000, 'type' => 'deposit', 'paid_at' => now(), 'voided_at' => null]);

    // Newer shortfall: needs 40,000 (only 20,000 of credit will remain)
    $newer = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
        'shipping_area_id' => $area->id, 'subtotal' => 100000, 'total_amount' => 100000,
        'deposit_paid' => 60000, 'payment_status' => 'partial', 'ordered_at' => now(),
    ]);
    Payment::factory()->create(['order_id' => $newer->id, 'amount' => 60000, 'type' => 'deposit', 'paid_at' => now(), 'voided_at' => null]);

    app(CreditReallocationService::class)->reallocate($customer->id, $trip->id);

    // 50,000 total credit available: older gets its full 30,000 need first,
    // leaving only 20,000 for newer (still short by 20,000 after).
    expect((float) $older->fresh()->deposit_paid)->toBe(100000.0)
        ->and($older->fresh()->payment_status)->toBe('paid')
        ->and((float) $newer->fresh()->deposit_paid)->toBe(80000.0)
        ->and($newer->fresh()->payment_status)->toBe('partial');
});

test('writes an activity log entry when a reallocation happens', function () {
    $trip = Trip::factory()->open()->create(['created_by' => $this->adminUser()->id]);
    $admin = $this->adminUser();
    $area  = $this->shippingArea();
    $customer = $this->customer($admin);

    $overpaid = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
        'shipping_area_id' => $area->id, 'subtotal' => 400000, 'total_amount' => 400000,
        'deposit_paid' => 500000, 'payment_status' => 'paid',
    ]);
    Payment::factory()->create(['order_id' => $overpaid->id, 'amount' => 500000, 'type' => 'deposit', 'paid_at' => now(), 'voided_at' => null]);

    $underpaid = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
        'shipping_area_id' => $area->id, 'subtotal' => 450000, 'total_amount' => 450000,
        'deposit_paid' => 400000, 'payment_status' => 'partial',
    ]);
    Payment::factory()->create(['order_id' => $underpaid->id, 'amount' => 400000, 'type' => 'deposit', 'paid_at' => now(), 'voided_at' => null]);

    app(CreditReallocationService::class)->reallocate($customer->id, $trip->id);

    expect(ActivityLog::where('action', 'payment.auto_reallocated')
        ->where('subject_type', 'customer')
        ->where('subject_id', $customer->id)
        ->exists())->toBeTrue();
});

test('shipping price sync automatically triggers reallocation end-to-end', function () {
    $admin = $this->adminUser();
    $area  = ShippingArea::factory()->create(['price_per_kg' => 25000]);
    $trip  = Trip::factory()->open()->create(['created_by' => $admin->id]);
    $customer = $this->customer($admin);

    // Order 1: will become overpaid once shipping rate drops
    $product1 = Product::create(['trip_id' => $trip->id, 'product_code' => 'CR_'.fake()->unique()->numerify('###'), 'price' => 100000, 'weight_gram' => 500, 'status' => 'active']);
    $order1 = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
        'shipping_area_id' => $area->id, 'subtotal' => 100000, 'total_amount' => 125000,
        'deposit_paid' => 125000, 'payment_status' => 'paid', 'shipping_fee' => 25000,
        'ordered_at' => now()->subMinutes(10),
    ]);
    OrderItem::create(['order_id' => $order1->id, 'product_id' => $product1->id, 'quantity' => 1, 'unit_price' => 100000, 'line_total' => 100000, 'status' => 'pending']);
    Payment::factory()->create(['order_id' => $order1->id, 'amount' => 125000, 'type' => 'deposit', 'paid_at' => now(), 'voided_at' => null]);

    // Order 2: separate order, no shipping charge on it (anchor is order1),
    // but genuinely short by 20,000
    $product2 = Product::create(['trip_id' => $trip->id, 'product_code' => 'CR_'.fake()->unique()->numerify('###'), 'price' => 100000, 'weight_gram' => 500, 'status' => 'active']);
    $order2 = Order::factory()->create([
        'trip_id' => $trip->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
        'shipping_area_id' => $area->id, 'subtotal' => 100000, 'total_amount' => 100000,
        'deposit_paid' => 80000, 'payment_status' => 'partial', 'shipping_fee' => 0,
        'ordered_at' => now(),
    ]);
    OrderItem::create(['order_id' => $order2->id, 'product_id' => $product2->id, 'quantity' => 1, 'unit_price' => 100000, 'line_total' => 100000, 'status' => 'pending']);
    Payment::factory()->create(['order_id' => $order2->id, 'amount' => 80000, 'type' => 'deposit', 'paid_at' => now(), 'voided_at' => null]);

    // Drop the rate — order1's shipping_fee recalculates down, making it
    // overpaid; that credit should automatically flow to order2.
    $this->actingAs($admin)->put(route('shipping.update', $area), [
        'name' => $area->name, 'province' => $area->province,
        'price_per_kg' => 5000, 'is_active' => 1,
    ]);

    expect(Payment::where('order_id', $order2->id)->where('method', 'reallocation')->exists())->toBeTrue();
});