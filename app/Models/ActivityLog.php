<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class ActivityLog extends Model
{
    protected $fillable = [
        'user_id', 'user_name', 'action', 'description',
        'subject_type', 'subject_id', 'changes', 'ip_address',
    ];

    protected $casts = [
        'changes' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Record an audit-log entry. Designed to be a single, safe one-liner from
     * controllers. Never throws — if logging fails for any reason it is swallowed,
     * because an audit-log failure must never break the actual business action.
     *
     * @param string      $action       stable key, e.g. 'payment.voided'
     * @param string      $description  human-readable sentence
     * @param string|null $subjectType  e.g. 'order' | 'payment'
     * @param int|null    $subjectId    id of the affected record
     * @param array|null  $changes      optional ['field' => ['old' => x, 'new' => y]] for edits
     */
    public static function record(
        string $action,
        string $description,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?array $changes = null
    ): void {
        try {
            $user = Auth::user();
            static::create([
                'user_id'      => $user?->id,
                'user_name'    => $user?->name ?? 'System',
                'action'       => $action,
                'description'  => $description,
                'subject_type' => $subjectType,
                'subject_id'   => $subjectId,
                'changes'      => $changes,
                'ip_address'   => request()->ip(),
            ]);
        } catch (\Throwable $e) {
            // Audit logging must never break the real action it is recording.
            report($e);
        }
    }

    /** Bootstrap-coloured badge for the action type, for the admin view. */
    public function actionBadge(): string
    {
        $map = [
            'payment.recorded'      => 'bg-success',
            'payment.voided'        => 'bg-danger',
            'payment.batch_voided'  => 'bg-danger',
            'order.deleted'         => 'bg-danger',
            'order.bulk_deleted'    => 'bg-danger',
            'order.updated'         => 'bg-warning text-dark',
            'product.price_synced'  => 'bg-info',
            'shipping.price_synced' => 'bg-info',
        ];
        $cls = $map[$this->action] ?? 'bg-secondary';
        $label = ucwords(str_replace(['.', '_'], ' ', $this->action));
        return '<span class="badge ' . $cls . '">' . e($label) . '</span>';
    }
}