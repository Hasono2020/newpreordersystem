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
     * Rule: <= 1320g = 1kg, <= 2320g = 2kg, etc.
     */
    public static function calcChargeableKg(int $grams): float
    {
        if ($grams <= 0) return 0;
        if ($grams <= 1320) return 1;
        return ceil(($grams - 320) / 1000);
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
    /**
     * Build a [lowercase name => id] map for bulk imports (one query).
     * Areas with a blank name are excluded — an empty key makes
     * str_contains() match EVERY lookup and silently assign the wrong area.
     */
    public static function importLookup(): array
    {
        return static::query()->get(['id', 'name'])
            ->reduce(function (array $carry, $area) {
                $key = strtolower(trim((string) $area->name));
                if ($key !== '') {
                    $carry[$key] = $area->id;
                }
                return $carry;
            }, []);
    }

    /**
     * Resolve a spreadsheet area name against importLookup().
     *
     * Exact match always wins. Only then do we fall back to a substring
     * match, and the LONGEST matching area name wins rather than whichever
     * row the database happened to return first. That matters: with a
     * first-match-wins loop, an area named "JAK" would silently swallow
     * "JAKBAR", "JAKPUS" and "JAKUT", and which one you got depended on
     * arbitrary row order.
     *
     * Returns null when nothing matches — callers must handle that
     * explicitly rather than quietly saving a customer with no area.
     */
    public static function resolveFromLookup(string $needle, array $lookup): ?int
    {
        $key = strtolower(trim($needle));
        if ($key === '') {
            return null;
        }

        if (isset($lookup[$key])) {
            return $lookup[$key];
        }

        $bestId  = null;
        $bestLen = 0;
        foreach ($lookup as $name => $id) {
            $name = (string) $name; // numeric-looking names become int keys in PHP
            if ($name === '') {
                continue;
            }
            if (str_contains($name, $key) || str_contains($key, $name)) {
                if (strlen($name) > $bestLen) {
                    $bestLen = strlen($name);
                    $bestId  = $id;
                }
            }
        }

        return $bestId;
    }

    public function getSubsidyCap(): ?float
    {
        if ($this->isFlatFee()) {
            // Use explicit cap if set, otherwise cap at the flat fee itself
            return $this->flat_fee_subsidy_cap ?? $this->flat_fee;
        }

        return $this->flat_fee_subsidy_cap; // null for normal per-kg areas
    }
}