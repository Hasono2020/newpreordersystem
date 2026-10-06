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
        'sales_adjustment_id',
    ];

    protected $casts = [
        'paid_at'     => 'date',
        'voided_at'   => 'datetime',
        'verified_at' => 'datetime',
    ];

    public function order()        { return $this->belongsTo(Order::class); }
    public function salesAdjustment() { return $this->belongsTo(SalesAdjustment::class); }
    public function recordedBy()   { return $this->belongsTo(User::class, 'recorded_by'); }
    public function voidedBy()     { return $this->belongsTo(User::class, 'voided_by'); }
    public function verifiedBy()   { return $this->belongsTo(User::class, 'verified_by'); }

    public function isVoided(): bool      { return $this->voided_at !== null; }

    /**
     * "Sales Return" / "Credit Note" for a refund issued through that flow,
     * falling back to the plain type name (e.g. "Deposit") for everything
     * else, including a manually-recorded refund with no linked adjustment.
     */
    public function displayType(): string
    {
        if ($this->type === 'refund' && $this->salesAdjustment) {
            return $this->salesAdjustment->isReturn() ? 'Sales Return' : 'Credit Note';
        }
        return ucfirst($this->type);
    }
    /**
     * The Credit Note / Sales Return number (e.g. CR/B4/10/000007, RP/B4/10/000007)
     * behind a refund, or null for an ordinary payment. Read from the linked
     * adjustment itself rather than the free-text "reference" field, which anyone
     * could have typed over.
     */
    public function documentNumber(): ?string
    {
        return $this->type === 'refund' ? $this->salesAdjustment?->adjustment_number : null;
    }

    public function isVerified(): bool    { return $this->verification_status === 'verified'; }
    public function isDisputed(): bool    { return $this->verification_status === 'disputed'; }
    public function isUnverified(): bool  { return $this->verification_status === 'unverified'; }

    public function getEffectiveAmountAttribute(): float
    {
        if ($this->isVoided()) return 0;
        return $this->type === 'refund' ? -$this->amount : $this->amount;
    }
}