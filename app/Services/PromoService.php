<?php

namespace App\Services;

use App\Models\PromoRule;

class PromoService
{
    /**
     * Find the best applicable promo for a customer type, trip, and item count.
     * Returns the promo breakdown or null.
     */
    public function getBestPromo(string $customerType, int $tripId, int $itemCount): ?array
    {
        $rules = PromoRule::where('is_active', true)
            ->where(function ($q) use ($tripId) {
                $q->where('trip_id', $tripId)->orWhereNull('trip_id');
            })
            ->where('min_items', '<=', $itemCount)
            ->orderByDesc('min_items')
            ->get();

        $best = null;
        $bestDiscount = -1;

        foreach ($rules as $rule) {
            if (!$rule->appliesTo($customerType, $itemCount)) continue;
            $calc = $rule->calculateDiscount($itemCount);
            $totalBenefit = $calc['discount'] + $calc['max_shipping_subsidy'];
            if ($totalBenefit > $bestDiscount) {
                $bestDiscount = $totalBenefit;
                $best = [
                    'rule' => $rule,
                    'discount' => $calc['discount'],
                    'max_shipping_subsidy' => $calc['max_shipping_subsidy'],
                ];
            }
        }

        return $best;
    }

    /**
     * Recalculate order totals applying promos.
     */
    public function recalculate(\App\Models\Order $order): array
    {
        $order->load('items.variant', 'customer');

        $activeItems = $order->items->whereNotIn('status', ['cancelled', 'sold_out']);
        $subtotal = $activeItems->sum('line_total');
        $itemCount = $activeItems->sum('quantity');

        $promo = $this->getBestPromo(
            $order->customer->type,
            $order->trip_id,
            $itemCount
        );

        $discount = $promo ? $promo['discount'] : 0;
        $maxShippingSubsidy = $promo ? $promo['max_shipping_subsidy'] : 0;
        $shippingFee = $order->shipping_fee ?? 0;
        $shippingDiscount = min($shippingFee, $maxShippingSubsidy);

        $total = $subtotal - $discount + $shippingFee - $shippingDiscount;

        return [
            'subtotal' => $subtotal,
            'discount_amount' => $discount,
            'shipping_discount' => $shippingDiscount,
            'total_amount' => max(0, $total),
            'promo_rule' => $promo ? $promo['rule'] : null,
        ];
    }
}
