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
}