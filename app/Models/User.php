<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name', 'email', 'password', 'role',
        'permissions', 'phone', 'is_active', 'notes',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'permissions'       => 'array',
            'is_active'         => 'boolean',
        ];
    }

    // ── Role helpers ──────────────────────────────────────────────────

    public function isAdmin(): bool     { return $this->role === 'admin'; }
    public function isFinance(): bool   { return in_array($this->role, ['admin', 'finance']); }
    public function isPurchasing(): bool{ return in_array($this->role, ['admin', 'purchasing']); }
    public function isStaff(): bool     { return $this->role === 'staff'; }
    public function isViewer(): bool    { return $this->role === 'viewer'; }
    public function isActive(): bool    { return $this->is_active; }

    /**
     * Role default permissions.
     * Each key maps to a module/action — true = allowed, false = denied.
     */
    public static function roleDefaults(string $role): array
    {
        return match($role) {
            'admin' => [
                'orders.view'      => true,  'orders.create'    => true,
                'orders.edit'      => true,  'orders.delete'    => true,
                'orders.import'    => true,
                'customers.view'   => true,  'customers.create' => true,
                'customers.edit'   => true,  'customers.delete' => true,
                'customers.import' => true,
                'products.view'    => true,  'products.create'  => true,
                'products.edit'    => true,  'products.delete'  => true,
                'purchasing.view'  => true,  'purchasing.edit'  => true,
                'payments.view'    => true,  'payments.record'  => true,
                'payments.void'    => true,
                'invoices.view'    => true,
                'trips.view'       => true,  'trips.edit'       => true,
                'reports.view'     => true,
                'settings.view'    => true,  'settings.edit'    => true,
            ],
            'finance' => [
                'orders.view'      => true,  'orders.create'    => false,
                'orders.edit'      => false, 'orders.delete'    => false,
                'orders.import'    => false, 'orders.export'    => true,
                'customers.view'   => true,  'customers.create' => false,
                'customers.edit'   => false, 'customers.delete' => false,
                'customers.import' => false,
                'products.view'    => true,  'products.create'  => false,
                'products.edit'    => false, 'products.delete'  => false,
                'products.import'  => false,
                'suppliers.view'   => true,  'suppliers.create' => false,
                'suppliers.edit'   => false, 'suppliers.delete' => false,
                'shipping.view'    => true,  'shipping.create'  => false,
                'shipping.edit'    => false, 'shipping.delete'  => false,
                'shipping.import'  => false,
                'purchasing.view'  => true,  'purchasing.edit'  => false,
                'payments.view'    => true,  'payments.record'  => true,
                'payments.void'    => true,
                'invoices.view'    => true,
                'trips.view'       => true,  'trips.edit'       => false,
                'trips.new_order'  => false,
                'reports.view'     => true,
                'settings.view'    => false, 'settings.edit'    => false,
                'promos.edit'      => false,
            ],
            'purchasing' => [
                'orders.view'      => true,  'orders.create'    => false,
                'orders.edit'      => false, 'orders.delete'    => false,
                'orders.import'    => false,
                'customers.view'   => true,  'customers.create' => false,
                'customers.edit'   => false, 'customers.delete' => false,
                'customers.import' => false,
                'products.view'    => true,  'products.create'  => true,
                'products.edit'    => true,  'products.delete'  => false,
                'purchasing.view'  => true,  'purchasing.edit'  => true,
                'payments.view'    => false, 'payments.record'  => false,
                'payments.void'    => false,
                'invoices.view'    => false,
                'trips.view'       => true,  'trips.edit'       => false,
                'reports.view'     => false,
                'settings.view'    => false, 'settings.edit'    => false,
            ],
            'staff' => [
                'orders.view'      => true,  'orders.create'    => true,
                'orders.edit'      => true,  'orders.delete'    => false,
                'orders.import'    => true,
                'customers.view'   => true,  'customers.create' => true,
                'customers.edit'   => true,  'customers.delete' => false,
                'customers.import' => true,
                'products.view'    => true,  'products.create'  => false,
                'products.edit'    => false, 'products.delete'  => false,
                'purchasing.view'  => true,  'purchasing.edit'  => false,
                'payments.view'    => true,  'payments.record'  => true,
                'payments.void'    => false,
                'invoices.view'    => true,
                'trips.view'       => true,  'trips.edit'       => false,
                'reports.view'     => false,
                'settings.view'    => false, 'settings.edit'    => false,
            ],
            'viewer' => [
                'orders.view'      => true,  'orders.create'    => false,
                'orders.edit'      => false, 'orders.delete'    => false,
                'orders.import'    => false,
                'customers.view'   => true,  'customers.create' => false,
                'customers.edit'   => false, 'customers.delete' => false,
                'customers.import' => false,
                'products.view'    => true,  'products.create'  => false,
                'products.edit'    => false, 'products.delete'  => false,
                'purchasing.view'  => true,  'purchasing.edit'  => false,
                'payments.view'    => false, 'payments.record'  => false,
                'payments.void'    => false,
                'invoices.view'    => true,
                'trips.view'       => true,  'trips.edit'       => false,
                'reports.view'     => true,
                'settings.view'    => false, 'settings.edit'    => false,
            ],
            default => [],
        };
    }

    /**
     * Check if user has a specific permission.
     * Custom permissions override role defaults.
     * Use this instead of can() to avoid conflict with Laravel's Gate.
     */
    public function hasPermission(string $permission): bool
    {
        if (!$this->is_active) return false;
        if ($this->role === 'admin') return true;

        $custom = $this->permissions ?? [];
        if (array_key_exists($permission, $custom)) {
            return (bool) $custom[$permission];
        }

        $defaults = self::roleDefaults($this->role);
        return (bool) ($defaults[$permission] ?? false);
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'created_by');
    }
}