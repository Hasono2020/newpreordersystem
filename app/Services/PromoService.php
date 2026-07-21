<?php

namespace App\Services;

use App\Models\PromoRule;
use App\Models\ShippingArea;

class PromoService
{
    /**
     * Find the best applicable promo for a customer type, trip, item count,
     * considering only eligible (non-excluded) items.
     */
    private array $ruleCache = [];

    /**
     * Fix #12: clear the rule cache between trips when the same PromoService
     * instance is reused (e.g. during bulk import across multiple trips).
     */
    public function clearCache(): void
    {
        $this->ruleCache = [];
    }

    public function getBestPromo(string $customerType, int $tripId, \Illuminate\Support\Collection $activeItems): ?array
    {
        if (!isset($this->ruleCache[$tripId])) {
            $this->ruleCache[$tripId] = PromoRule::where('is_active', true)
                ->where(function ($q) use ($tripId) {
                    $q->where('trip_id', $tripId)->orWhereNull('trip_id');
                })
                ->orderByDesc('min_items')
                ->get();
        }
        $rules = $this->ruleCache[$tripId];

        $best        = null;
        $bestDiscount = -1;

        foreach ($rules as $rule) {
            // Filter out excluded product codes
            $eligibleItems = $rule->filterEligibleItems($activeItems);
            $itemCount     = $eligibleItems->sum('quantity');

            if (!$rule->appliesTo($customerType, $itemCount)) continue;

            $calc         = $rule->calculateDiscount($itemCount);
            $totalBenefit = $calc['discount'] + $calc['max_shipping_subsidy'];

            if ($totalBenefit > $bestDiscount) {
                $bestDiscount = $totalBenefit;
                $best = [
                    'rule'                => $rule,
                    'discount'            => $calc['discount'],
                    'max_shipping_subsidy'=> $calc['max_shipping_subsidy'],
                    'eligible_item_count' => $itemCount,
                ];
            }
        }

        return $best;
    }

    /**
     * Calculate total weight in grams from active order items.
     */
    public function calcTotalWeightGram(\Illuminate\Support\Collection $activeItems): int
    {
        $total = 0;
        foreach ($activeItems as $item) {
            $weight = $item->product?->weight_gram ?? 0;
            $total += $weight * $item->quantity;
        }
        return $total;
    }

    /**
     * Recalculate order totals applying promos and weight-based shipping.
     */
    public function recalculate(\App\Models\Order $order): array
    {
        $order->loadMissing('items.product', 'items.variant', 'customer', 'shippingArea');

        $activeItems = $order->items->whereNotIn('status', ['cancelled', 'sold_out']);
        $subtotal    = $activeItems->sum('line_total');

        // Weight-based shipping
        $totalGrams    = $this->calcTotalWeightGram($activeItems);
        $chargeableKg  = ShippingArea::calcChargeableKg($totalGrams);
        $shippingFee   = $order->shippingArea
            ? $order->shippingArea->calcShippingFee($totalGrams)
            : 0;

        // Promo
        $promo              = $this->getBestPromo($order->customer->type, $order->trip_id, $activeItems);
        $discount           = $promo ? $promo['discount'] : 0;
        $maxShippingSubsidy = $promo ? $promo['max_shipping_subsidy'] : 0;

        // Area-level subsidy cap: flat-fee areas cap how much of the fee can be subsidised
        $areaCap          = $order->shippingArea?->getSubsidyCap();
        if ($areaCap !== null) {
            $maxShippingSubsidy = min($maxShippingSubsidy, $areaCap);
        }
        $shippingDiscount   = min($shippingFee, $maxShippingSubsidy);

        $total = $subtotal - $discount + $shippingFee - $shippingDiscount;

        return [
            'subtotal'             => $subtotal,
            'discount_amount'      => $discount,
            'shipping_fee'         => $shippingFee,
            'shipping_discount'    => $shippingDiscount,
            'shipping_weight_gram' => $totalGrams,
            'shipping_kg_charged'  => $chargeableKg,
            'total_amount'         => max(0, $total),
            'promo_rule'           => $promo ? $promo['rule'] : null,
        ];
    }

    /**
     * Recalculate shipping across ALL of a customer's orders in a trip so shipping is
     * charged ONCE (combined weight) on the oldest order (the shipment "anchor"),
     * and zeroed on the rest. Keeps every screen consistent: the per-order totals
     * now sum to the true combined amount the customer owes.
     *
     * Called after any order create / update / item change / delete.
     */
    public function recalcCustomerShipping(int $customerId, int $tripId): void
    {
        $orders = \App\Models\Order::with('items.product', 'items.variant', 'customer', 'shippingArea')
            ->where('customer_id', $customerId)
            ->where('trip_id', $tripId)
            ->orderByRaw('COALESCE(ordered_at, created_at) ASC')
            ->orderBy('id')
            ->get();

        if ($orders->isEmpty()) return;

        // Combined weight + items across ALL the customer's orders
        $allActiveItems = $orders->flatMap(fn($o) =>
            $o->items->whereNotIn('status', ['cancelled', 'sold_out'])
        );
        $combinedGrams = $this->calcTotalWeightGram($allActiveItems);

        // Pick a shipping area (first order that has one)
        $shippingArea = $orders->first(fn($o) => $o->shippingArea)?->shippingArea;
        $combinedShippingFee = $shippingArea ? $shippingArea->calcShippingFee($combinedGrams) : 0;
        $combinedKg          = \App\Models\ShippingArea::calcChargeableKg($combinedGrams);

        // Evaluate the promo ONCE on all combined items (so multi-order customers reach
        // item-count thresholds like '5+ items'). Discount + shipping subsidy land on the anchor.
        $customerType       = $orders->first()->customer->type;
        $combinedPromo      = $this->getBestPromo($customerType, $tripId, $allActiveItems);
        $combinedDiscount    = $combinedPromo ? $combinedPromo['discount'] : 0;
        $combinedShipSubsidy = $combinedPromo ? $combinedPromo['max_shipping_subsidy'] : 0;

        // Area-level subsidy cap for flat-fee areas
        $areaCap = $shippingArea?->getSubsidyCap();
        if ($areaCap !== null) {
            $combinedShipSubsidy = min($combinedShipSubsidy, $areaCap);
        }

        // The anchor = first order that still has active items (oldest). It carries shipping + promo.
        $anchor = $orders->first(fn($o) =>
            $o->items->whereNotIn('status', ['cancelled', 'sold_out'])->isNotEmpty()
        ) ?? $orders->first();

        // All-or-nothing: every order in this customer+trip group is recalculated together.
        // Without this, a failure halfway through could leave the customer's orders with
        // mismatched totals (e.g. one order charged shipping, another not).
        \DB::transaction(function () use ($orders, $anchor, $combinedShippingFee, $combinedGrams, $combinedKg, $combinedDiscount, $combinedShipSubsidy) {
            foreach ($orders as $order) {
                $activeItems = $order->items->whereNotIn('status', ['cancelled', 'sold_out']);
                $subtotal    = $activeItems->sum('line_total');

                $isAnchor    = $order->id === $anchor->id;
                $shippingFee = $isAnchor ? $combinedShippingFee : 0;
                $weightGram  = $isAnchor ? $combinedGrams : 0;
                $kgCharged   = $isAnchor ? $combinedKg : 0;

                // Combined promo applies to the anchor only (discount + shipping subsidy charged once)
                $discount         = $isAnchor ? $combinedDiscount : 0;
                $shippingDiscount = $isAnchor ? min($shippingFee, $combinedShipSubsidy) : 0;

                $total = max(0, $subtotal - $discount + $shippingFee - $shippingDiscount);

                $order->update([
                    'subtotal'             => $subtotal,
                    'discount_amount'      => $discount,
                    'shipping_fee'         => $shippingFee,
                    'shipping_discount'    => $shippingDiscount,
                    'shipping_weight_gram' => $weightGram,
                    'shipping_kg_charged'  => $kgCharged,
                    'total_amount'         => $total,
                ]);
            }
        });
    }
}