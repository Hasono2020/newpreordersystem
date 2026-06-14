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
        'verification_status', 'verified_by', 'verified_at', 'dispute_note',
    ];

    protected $casts = [
        'paid_at'     => 'date',
        'voided_at'   => 'datetime',
        'verified_at' => 'datetime',
    ];

    public function order()        { return $this->belongsTo(Order::class); }
    public function recordedBy()   { return $this->belongsTo(User::class, 'recorded_by'); }
    public function voidedBy()     { return $this->belongsTo(User::class, 'voided_by'); }
    public function verifiedBy()   { return $this->belongsTo(User::class, 'verified_by'); }

    public function isVoided(): bool      { return $this->voided_at !== null; }
    public function isVerified(): bool    { return $this->verification_status === 'verified'; }
    public function isDisputed(): bool    { return $this->verification_status === 'disputed'; }
    public function isUnverified(): bool  { return $this->verification_status === 'unverified'; }

    public function getEffectiveAmountAttribute(): float
    {
        if ($this->isVoided()) return 0;
        return $this->type === 'refund' ? -$this->amount : $this->amount;
    }
}