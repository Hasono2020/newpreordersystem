<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShippingArea extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'province', 'price_per_kg',
        'flat_fee', 'flat_fee_subsidy_cap',
        'is_active', 'notes',
    ];

    protected $casts = [
        'is_active'           => 'boolean',
        'flat_fee'            => 'float',
        'flat_fee_subsidy_cap'=> 'float',
    ];

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Whether this area uses a flat fee instead of per-kg pricing.
     */
    public function isFlatFee(): bool
    {
        return $this->flat_fee !== null && $this->flat_fee > 0;
    }

    /**
     * Calculate chargeable kg from total grams.
     * Rule: <= 1350g = 1kg, <= 2350g = 2kg, etc.
     */
    public static function calcChargeableKg(int $grams): float
    {
        if ($grams <= 0) return 0;
        if ($grams <= 1350) return 1;
        return ceil(($grams - 350) / 1000);
    }

    /**
     * Calculate shipping fee for given total grams.
     * Returns flat_fee when set, otherwise weight-based fee.
     */
    public function calcShippingFee(int $grams): float
    {
        if ($grams <= 0) return 0;

        if ($this->isFlatFee()) {
            return (float) $this->flat_fee;
        }

        $kg = self::calcChargeableKg($grams);
        return $kg * $this->price_per_kg;
    }

    /**
     * The maximum shipping subsidy this area allows.
     * For flat-fee areas, the cap is the flat fee itself (or flat_fee_subsidy_cap if set).
     * For per-kg areas, null means no area-level cap (promo rule decides).
     */
    public function getSubsidyCap(): ?float
    {
        if ($this->isFlatFee()) {
            // Use explicit cap if set, otherwise cap at the flat fee itself
            return $this->flat_fee_subsidy_cap ?? $this->flat_fee;
        }

        return $this->flat_fee_subsidy_cap; // null for normal per-kg areas
    }
}
