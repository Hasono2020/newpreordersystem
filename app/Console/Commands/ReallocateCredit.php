<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Payment;
use App\Services\CreditReallocationService;
use Illuminate\Console\Command;

/**
 * One-time catch-up for overpay/underpay splits that already existed
 * before CreditReallocationService was wired into the price-sync flow.
 * Going forward, new product/shipping price changes trigger this
 * automatically — this command is only for fixing historical data.
 *
 * Safe to run more than once: reallocate() is a no-op for any
 * customer/trip pair that has nothing left to reallocate.
 */
class ReallocateCredit extends Command
{
    protected $signature = 'payments:reallocate-credit {--trip= : Only this trip ID} {--all : Every trip}';
    protected $description = 'One-time catch-up: reallocate existing overpayment credit to cover underpaid orders for the same customer/trip. New price syncs do this automatically going forward.';

    public function handle(CreditReallocationService $service): int
    {
        $tripId = $this->option('trip');
        $all    = $this->option('all');

        if (!$tripId && !$all) {
            $this->error('Specify --trip=ID for one trip, or --all for every trip.');
            return self::FAILURE;
        }

        $query = Order::query()->select('customer_id', 'trip_id')->distinct();
        if ($tripId) {
            $query->where('trip_id', (int) $tripId);
        }
        $pairs = $query->get();

        if ($pairs->isEmpty()) {
            $this->error('No matching orders found.');
            return self::FAILURE;
        }

        $this->info("Checking {$pairs->count()} customer/trip group(s) for overpay/underpay splits...");

        $bar = $this->output->createProgressBar($pairs->count());
        $reallocatedCount = 0;

        foreach ($pairs as $pair) {
            $before = Payment::where('method', 'reallocation')->count();
            $service->reallocate($pair->customer_id, $pair->trip_id);
            $after = Payment::where('method', 'reallocation')->count();

            if ($after > $before) {
                $reallocatedCount++;
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
        $this->info("Done. {$reallocatedCount} customer/trip group(s) had credit reallocated.");

        return self::SUCCESS;
    }
}
