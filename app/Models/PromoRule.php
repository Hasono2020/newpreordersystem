<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PromoRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'description', 'min_items', 'discount_per_item',
        'discount_flat', 'max_shipping_subsidy', 'eligible_customer_types',
        'excluded_product_codes', 'trip_id', 'is_active',
    ];

    protected $casts = [
        'eligible_customer_types' => 'array',
        'excluded_product_codes'  => 'array',
        'is_active'               => 'boolean',
    ];

    public function trip() { return $this->belongsTo(Trip::class); }

    public function appliesTo(string $customerType, int $itemCount): bool
    {
        if (!$this->is_active) return false;
        if ($itemCount < $this->min_items) return false;

        // Guard: eligible_customer_types should be an array from the JSON cast.
        // If it somehow came back as a string (e.g. double-encoded JSON), decode it.
        $types = $this->eligible_customer_types;
        if (is_string($types)) {
            $types = json_decode($types, true) ?? [];
        }

        if (!empty($types) && !in_array($customerType, $types)) {
            return false;
        }

        return true;
    }

    /**
     * Filter items eligible for this promo.
     * Excludes:
     *  1. Products flagged excluded_from_promo = true (product-level toggle)
     *  2. Products whose code prefix matches excluded_product_codes on this rule
     */
    public function filterEligibleItems($items)
    {
        $excludedCodes = array_map('strtoupper', $this->excluded_product_codes ?? []);

        return $items->filter(function ($item) use ($excludedCodes) {
            $product = $item->product;

            // If product relation isn't loaded/found, include the item
            // (don't silently exclude it, which would break promo item counts)
            if (!$product) return true;

            // Product-level flag takes priority
            if ($product->excluded_from_promo) return false;

            // Rule-level code prefix exclusion — guard against null code_prefix
            if (!empty($excludedCodes)) {
                $prefix = strtoupper($product->code_prefix ?? '');
                if ($prefix !== '' && in_array($prefix, $excludedCodes)) return false;
            }

            return true;
        });
    }

    public function calculateDiscount(int $itemCount): array
    {
        // A rule uses EITHER flat discount OR per-item discount, not both simultaneously.
        // Per-item discount takes priority if set; otherwise use flat.
        $discount = $this->discount_per_item > 0
            ? $this->discount_per_item * $itemCount
            : ($this->discount_flat ?? 0);

        return [
            'discount'             => $discount,
            'max_shipping_subsidy' => $this->max_shipping_subsidy,
        ];
    }
}