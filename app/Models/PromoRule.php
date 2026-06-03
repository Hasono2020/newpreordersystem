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
        'trip_id', 'is_active',
    ];

    protected $casts = [
        'eligible_customer_types' => 'array',
        'is_active' => 'boolean',
    ];

    public function trip()
    {
        return $this->belongsTo(Trip::class);
    }

    /**
     * Check if this promo applies to a customer type and item count.
     */
    public function appliesTo(string $customerType, int $itemCount): bool
    {
        if (!$this->is_active) return false;
        if ($itemCount < $this->min_items) return false;
        if ($this->eligible_customer_types && !in_array($customerType, $this->eligible_customer_types)) {
            return false;
        }
        return true;
    }

    /**
     * Calculate discount for a given item count.
     */
    public function calculateDiscount(int $itemCount): array
    {
        $discount = $this->discount_flat + ($this->discount_per_item * $itemCount);
        return [
            'discount' => $discount,
            'max_shipping_subsidy' => $this->max_shipping_subsidy,
        ];
    }
}
