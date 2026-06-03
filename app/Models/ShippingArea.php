<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShippingArea extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'province', 'price_per_kg', 'is_active', 'notes'];

    protected $casts = ['is_active' => 'boolean'];

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Calculate chargeable kg from total grams.
     * Rule: <= 1350g = 1kg, <= 2350g = 2kg, etc.
     * Formula: ceil((grams - 350) / 1000) but minimum 1
     */
    public static function calcChargeableKg(int $grams): float
    {
        if ($grams <= 0) return 0;
        if ($grams <= 1350) return 1;
        return ceil(($grams - 350) / 1000);
    }

    /**
     * Calculate shipping fee for given total grams.
     */
    public function calcShippingFee(int $grams): float
    {
        $kg = self::calcChargeableKg($grams);
        return $kg * $this->price_per_kg;
    }
}
