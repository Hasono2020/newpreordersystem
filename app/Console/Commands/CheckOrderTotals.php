<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Trip;
use App\Services\PromoService;
use Illuminate\Console\Command;

/**
 * Finds customers whose SAVED order totals no longer match what their invoice shows.
 *
 * The invoice is always worked out fresh, from the current items, weight, shipping rate and promo
 * (shipping and promo once per customer per trip, on the oldest order). The totals saved on the
 * orders are only refreshed when something triggers a recalculation — so if one is missed, the
 * Orders list, balances, payment status and the Shipment tab keep showing the old numbers
 * while the invoice says something else.
 *
 * Without --fix this only READS: nothing is changed. With --fix it recalculates exactly the
 * customers it listed (the same recalculation an order edit does: new totals, new paid status,
 * spare payments moved onto orders that are short).
 */
class CheckOrderTotals extends Command
{
    protected $signature = 'orders:check-totals
        {--trip= : Only this trip ID (even if it is closed)}
        {--include-closed : Also look at closed trips (without --trip, closed trips are skipped)}
        {--fix : Recalculate the customers listed — this SAVES new totals}
        {--limit=50 : How many mismatches to list (all of them are still counted and fixed)}';

    protected $description = 'List customers whose saved order totals differ from their invoice; --fix recalculates them.';

    /** Columns that make up what a customer owes. */
    private const COLUMNS = ['subtotal', 'discount_amount', 'shipping_fee', 'shipping_discount', 'total_amount'];

    public function handle(PromoService $promoService): int
    {
        $tripIds = Trip::query()
            ->when($this->option('trip'), fn($q) => $q->where('id', (int) $this->option('trip')))
            ->when(!$this->option('trip') && !$this->option('include-closed'), fn($q) => $q->where('status', '!=', 'closed'))
            ->orderBy('id')->pluck('id');

        if ($tripIds->isEmpty()) {
            $this->error('No matching trip found.');
            return self::FAILURE;
        }

        $checked  = 0;
        $mismatch = []; // [customer_id, trip_id, name, phone, orders, saved, correct, paid]

        foreach ($tripIds as $tripId) {
            $customerIds = Order::where('trip_id', $tripId)->distinct()->pluck('customer_id');

            foreach ($customerIds->chunk(100) as $chunk) {
                $byCustomer = Order::with('items.product', 'items.variant', 'customer', 'shippingArea')
                    ->where('trip_id', $tripId)->whereIn('customer_id', $chunk)
                    ->orderByRaw('COALESCE(ordered_at, created_at) ASC')->orderBy('id')
                    ->get()->groupBy('customer_id');

                foreach ($byCustomer as $customerId => $orders) {
                    $checked++;
                    $allocation = $promoService->allocateCustomerTotals($orders, $tripId);

                    $differs = false;
                    foreach ($orders as $order) {
                        foreach (self::COLUMNS as $col) {
                            if (abs((float) $order->{$col} - (float) $allocation[$order->id][$col]) > 0.5) {
                                $differs = true;
                                break 2;
                            }
                        }
                    }
                    if (!$differs) continue;

                    $customer = $orders->first()->customer;
                    $mismatch[] = [
                        'customer_id' => (int) $customerId,
                        'trip_id'     => (int) $tripId,
                        'name'        => $customer->name ?? '?',
                        'phone'       => $customer->phone ?? '',
                        'orders'      => $orders->count(),
                        'saved'       => (float) $orders->sum('total_amount'),
                        'correct'     => (float) collect($allocation)->sum('total_amount'),
                        'paid'        => (float) $orders->sum('deposit_paid'),
                    ];
                }
            }
        }

        $this->info("Checked {$checked} customer/trip group(s) in {$tripIds->count()} trip(s).");

        if (!$mismatch) {
            $this->info('Every saved total matches its invoice. Nothing to fix.');
            return self::SUCCESS;
        }

        $this->warn(count($mismatch) . ' customer(s) have saved totals that differ from their invoice:');
        $limit = max(1, (int) $this->option('limit'));
        $this->table(
            ['Trip', 'Customer', 'Phone', 'Orders', 'Saved total', 'Invoice total', 'Difference', 'Paid'],
            collect($mismatch)->take($limit)->map(fn($m) => [
                $m['trip_id'], $m['name'], $m['phone'], $m['orders'],
                number_format($m['saved'], 0, ',', '.'), number_format($m['correct'], 0, ',', '.'),
                ($m['saved'] - $m['correct'] > 0 ? '+' : '') . number_format($m['saved'] - $m['correct'], 0, ',', '.'),
                number_format($m['paid'], 0, ',', '.'),
            ])->all()
        );
        if (count($mismatch) > $limit) {
            $this->line('… and ' . (count($mismatch) - $limit) . ' more (use --limit=N to list more).');
        }

        if (!$this->option('fix')) {
            $this->line('');
            $this->line('Nothing was changed. To recalculate these customers, run the same command again with --fix.');
            return self::SUCCESS;
        }

        $this->line('');
        $this->info('Recalculating ' . count($mismatch) . ' customer(s)…');
        $bar = $this->output->createProgressBar(count($mismatch));
        foreach ($mismatch as $m) {
            $promoService->recalcCustomerShipping($m['customer_id'], $m['trip_id']);
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
        $this->info('Done. Run the command without --fix again to confirm nothing is left.');

        return self::SUCCESS;
    }
}
