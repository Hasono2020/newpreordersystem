<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One sending: some of a customer's packages, on one date.
 *
 * @property int $id
 * @property int $shipment_id
 * @property int $packages
 * @property \Illuminate\Support\Carbon $shipped_on
 * @property int|null $created_by
 */
class ShipmentBatch extends Model
{
    protected $fillable = ['shipment_id', 'packages', 'shipped_on', 'created_by'];

    protected $casts = ['shipped_on' => 'date', 'packages' => 'integer'];

    public function shipment() { return $this->belongsTo(Shipment::class); }
    public function createdBy() { return $this->belongsTo(User::class, 'created_by'); }
}
