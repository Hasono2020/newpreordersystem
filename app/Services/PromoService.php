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
    /**
     * Total chargeable weight across a set of active items, optionally
     * bumped by the customer's "use cargo" flag.
     *
     * The +1000g only applies once here, regardless of how many times
     * this is called per shipment — callers combining multiple orders
     * into one shipment (recalcCustomerShipping) must call this ONCE on
     * the combined item set, not once per order, or the bump would
     * effectively multiply per order despite the intended "once per
     * shipment" behavior.
     */
    public function calcTotalWeightGram(\Illuminate\Support\Collection $activeItems, bool $useCargo = false): int
    {
        $total = 0;
        foreach ($activeItems as $item) {
            $weight = $item->product?->weight_gram ?? 0;
            $total += $weight * $item->quantity;
        }
        // No bump for an empty/zero-weight shipment — nothing is actually
        // being shipped, so there's no package to add cargo weight to.
        if ($useCargo && $total > 0) {
            $total += 1000;
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
        $totalGrams    = $this->calcTotalWeightGram($activeItems, (bool) ($order->customer->use_cargo ?? false));
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
    /**
     * Active (non-cancelled, non-sold-out) items across ALL of a customer's
     * orders in one trip. Single source of truth for "combined" promo/
     * shipping eligibility — item-count thresholds like "30+ items" are
     * meant to be met across a customer's whole trip, not per order.
     *
     * Was previously duplicated inline inside recalcCustomerShipping(),
     * while OrderController::show() computed its OWN promo-status display
     * from $order->items alone — so a customer whose combined orders
     * genuinely qualified for a promo (and had it correctly applied to
     * their total) could still see a "No promo applied — needs N more
     * items" banner on the order page, because that banner was counting
     * only the order being viewed.
     */
    public function combinedActiveItems(int $customerId, int $tripId): \Illuminate\Support\Collection
    {
        $orders = \App\Models\Order::with('items')
            ->where('customer_id', $customerId)
            ->where('trip_id', $tripId)
            ->get();

        return $orders->flatMap(fn($o) =>
            $o->items->whereNotIn('status', ['cancelled', 'sold_out'])
        );
    }

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
        // The +1000g bump applies ONCE here, on the combined set — not per
        // order — matching the "one shipment, one bump" behavior even when
        // several of the customer's orders in this trip are being combined.
        $useCargo      = (bool) ($orders->first()->customer->use_cargo ?? false);
        $combinedGrams = $this->calcTotalWeightGram($allActiveItems, $useCargo);

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

                // recalcPaymentStatus() reads $this->total_amount, so it MUST run
                // after the update above, not before. Without this, changing a
                // total here (a price sync, a promo becoming eligible, a shipping
                // rate change, cargo being toggled, etc.) leaves payment_status
                // and deposit_paid exactly as they were under the OLD total —
                // an order can end up genuinely overpaid while still reading
                // "Partially Paid" indefinitely, because nothing ever re-derives
                // it against the new total until a NEW payment is recorded.
                $order->recalcPaymentStatus();
            }
        });
    }
}