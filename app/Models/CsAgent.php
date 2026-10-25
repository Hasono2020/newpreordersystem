<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CsAgent extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'handle', 'is_active', 'notes'];

    protected $casts = ['is_active' => 'boolean'];

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    /**
     * The CS checklist as words — "Dewi, Rina, No CS agent assigned" — for the Activity Log
     * and messages. $picked is what the checklist sends: agent ids and/or "none".
     * Empty when nothing was ticked.
     *
     * @param  array<int,mixed>|null $picked
     */
    public static function describePick(?array $picked): string
    {
        $picked = array_values(array_filter((array) $picked, fn ($v) => $v !== null && $v !== ''));
        $ids    = array_filter(array_map('intval', array_diff($picked, ['none'])));
        $names  = $ids === [] ? [] : static::whereIn('id', $ids)->orderBy('name')->pluck('name')->all();
        if (in_array('none', $picked, true)) {
            $names[] = 'No CS agent assigned';
        }
        return implode(', ', $names);
    }
}