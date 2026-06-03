<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = ['order_id', 'amount', 'type', 'method', 'reference', 'paid_at', 'notes', 'recorded_by'];

    protected $casts = [
        'paid_at' => 'date',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
