<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id', 'batch_id', 'amount', 'type', 'method', 'reference',
        'paid_at', 'notes', 'recorded_by',
        'voided_at', 'voided_by', 'void_reason',
    ];

    protected $casts = [
        'paid_at'   => 'date',
        'voided_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function voidedBy()
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    // Effective amount — voided payments count as 0
    public function getEffectiveAmountAttribute(): float
    {
        if ($this->isVoided()) return 0;
        return $this->type === 'refund' ? -$this->amount : $this->amount;
    }
}