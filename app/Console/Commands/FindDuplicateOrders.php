<?php

namespace App\Console\Commands;

use App\Models\Order;
use Illuminate\Console\Command;

class FindDuplicateOrders extends Command
{
    protected $signature = 'orders:find-duplicates
        {--days=30 : How many days back to scan}
        {--window=300 : Max seconds between two orders to still count them as a possible duplicate}
        {--log : Also write an Activity Log entry for pairs where the second order was created in the last 24h (safe to run daily without spamming old findings)}';

    protected $description = 'Scan for MANUALLY-created orders that look like accidental duplicate submissions — same customer, same trip, identical items and total, created moments apart. Import-batch orders are excluded on purpose (see note below).';

    public function handle(): int
    {
        $days   = (int) $this->option('days');
        $window = (int) $this->option('window');
        $log    = (bool) $this->option('log');

        // Only orders created one-at-a-time via the New Order form are in scope.
        // Excel imports can legitimately create several separate orders for the
        // same customer with identical items/total and the same created_at
        // timestamp (each spreadsheet row is its own real order) — that's
        // expected import behaviour, not an accidental duplicate.
        $orders = Order::with(['items', 'customer', 'trip'])
            ->where('source', 'manual')
            ->where('created_at', '>=', now()->subDays($days))
            ->orderBy('customer_id')->orderBy('trip_id')->orderBy('created_at')
            ->get()
            ->groupBy(fn (Order $o) => $o->trip_id . '-' . $o->customer_id);

        $found = [];

        foreach ($orders as $group) {
            $group = $group->values();
            for ($i = 0; $i < $group->count() - 1; $i++) {
                for ($j = $i + 1; $j < $group->count(); $j++) {
                    $a = $group[$i];
                    $b = $group[$j];

                    $gap = abs($b->created_at->diffInSeconds($a->created_at));
                    if ($gap > $window) continue;

                    $sameTotal = abs((float) $a->total_amount - (float) $b->total_amount) < 0.01;
                    if ($sameTotal && $this->sameItems($a, $b)) {
                        $found[] = [$a, $b, $gap];
                    }
                }
            }
        }

        if (empty($found)) {
            $this->info("No likely duplicate manual orders found in the last {$days} day(s). (Import-batch orders are not scanned — see command description.)");
            return self::SUCCESS;
        }

        $this->warn(count($found) . ' likely duplicate pair(s) found:');
        $rows = [];
        foreach ($found as [$a, $b, $gap]) {
            $rows[] = [
                $a->order_number,
                $b->order_number,
                $a->customer->name ?? "#{$a->customer_id}",
                $a->trip->name ?? "#{$a->trip_id}",
                'Rp ' . number_format((float) $a->total_amount, 0, ',', '.'),
                "{$gap}s apart",
                $a->created_at->format('d M Y H:i:s'),
            ];

            // Only log freshly-found pairs so a daily scheduled run doesn't
            // re-report the same old duplicate every single day.
            if ($log && $b->created_at->gte(now()->subDay())) {
                \App\Models\ActivityLog::record(
                    'order.possible_duplicate',
                    "Possible duplicate order detected: {$a->order_number} and {$b->order_number} — same customer, trip, items and total, {$gap}s apart.",
                    'order',
                    $b->id
                );
            }
        }
        $this->table(
            ['Order A', 'Order B', 'Customer', 'Trip', 'Total', 'Time gap', 'First created'],
            $rows
        );

        return self::SUCCESS;
    }

    /**
     * Two orders count as "same items" when they contain the exact same
     * set of product+variant+quantity lines, regardless of row order.
     */
    private function sameItems(Order $a, Order $b): bool
    {
        $signature = fn (Order $o) => $o->items
            ->map(fn ($item) => $item->product_id . '-' . $item->product_variant_id . '-' . $item->quantity)
            ->sort()->values()->all();

        return $signature($a) === $signature($b);
    }
}