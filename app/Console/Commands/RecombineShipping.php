<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\PromoService;
use Illuminate\Console\Command;

class RecombineShipping extends Command
{
    protected $signature = 'shipping:recombine {--trip= : Only recombine a specific trip ID}';
    protected $description = 'Recompute combined shipping for all customers (shipping charged once per customer per trip)';

    public function handle(PromoService $promoService): int
    {
        $query = Order::query()->select('customer_id', 'trip_id')->distinct();
        if ($this->option('trip')) {
            $query->where('trip_id', (int) $this->option('trip'));
        }

        $pairs = $query->get();
        $this->info("Recombining shipping for {$pairs->count()} customer/trip groups...");

        $bar = $this->output->createProgressBar($pairs->count());
        foreach ($pairs as $pair) {
            $promoService->recalcCustomerShipping($pair->customer_id, $pair->trip_id);
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
        $this->info('Done. All orders now use combined shipping.');

        return self::SUCCESS;
    }
}