<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The packages for one customer in one trip: how many there are in total, and (through
 * the batches) how many have been sent so far and on which dates.
 *
 * @property int $id
 * @property int $customer_id
 * @property int $trip_id
 * @property int|null $package_total
 * @property int|null $updated_by
 */
class Shipment extends Model
{
    protected $fillable = ['customer_id', 'trip_id', 'package_total', 'updated_by'];

    protected $casts = ['package_total' => 'integer'];

    public function customer() { return $this->belongsTo(Customer::class); }
    public function trip() { return $this->belongsTo(Trip::class); }
    public function updatedBy() { return $this->belongsTo(User::class, 'updated_by'); }

    /** @return HasMany<ShipmentBatch, $this> */
    public function batches(): HasMany { return $this->hasMany(ShipmentBatch::class)->orderBy('shipped_on')->orderBy('id'); }

    /** Packages sent so far, over all batches. */
    public function packagesSent(): int { return (int) ShipmentBatch::where('shipment_id', $this->id)->sum('packages'); }
}