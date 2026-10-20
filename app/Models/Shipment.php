<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What was sent to one customer for one trip: the number of packages and the shipping date.
 *
 * @property int $id
 * @property int $customer_id
 * @property int $trip_id
 * @property int|null $package_count
 * @property \Illuminate\Support\Carbon|null $shipped_on
 * @property int|null $updated_by
 */
class Shipment extends Model
{
    protected $fillable = ['customer_id', 'trip_id', 'package_count', 'shipped_on', 'updated_by'];

    protected $casts = ['shipped_on' => 'date', 'package_count' => 'integer'];

    public function customer() { return $this->belongsTo(Customer::class); }
    public function trip() { return $this->belongsTo(Trip::class); }
    public function updatedBy() { return $this->belongsTo(User::class, 'updated_by'); }
}
