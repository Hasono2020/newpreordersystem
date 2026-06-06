<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'phone', 'address', 'type', 'notes', 'default_shipping_area_id'];

    /**
     * Normalize phone: strip non-digits, ensure leading 0 for Indonesian numbers.
     * 81255553333 → 081255553333
     * +6281255553333 → 081255553333
     * 6281255553333 → 081255553333
     */
    public static function normalizePhone(?string $phone): ?string
    {
        if (empty($phone)) return null;

        // Strip everything except digits
        $digits = preg_replace('/\D/', '', $phone);

        if (empty($digits)) return null;

        // Strip country code 62 → replace with 0
        if (str_starts_with($digits, '62')) {
            $digits = '0' . substr($digits, 2);
        }

        // Ensure leading 0
        if (!str_starts_with($digits, '0')) {
            $digits = '0' . $digits;
        }

        return $digits;
    }

    protected static function boot()
    {
        parent::boot();

        // Normalize phone on every create/update
        static::saving(function ($customer) {
            $customer->phone = self::normalizePhone($customer->phone);
        });
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function defaultShippingArea()
    {
        return $this->belongsTo(ShippingArea::class, 'default_shipping_area_id');
    }

    public function getTypeLabelAttribute(): string
    {
        return match($this->type) {
            'reseller' => 'Reseller',
            'selected_customer' => 'Selected Customer',
            default => 'Customer',
        };
    }
}
