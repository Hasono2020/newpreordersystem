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
                'orders.import'    => true,  'orders.export'    => true,
                'customers.view'   => true,  'customers.create' => true,
                'customers.edit'   => true,  'customers.delete' => true,
                'customers.import' => true,  'customers.export' => true,
                'products.view'    => true,  'products.create'  => true,
                'products.edit'    => true,  'products.delete'  => true,
                'products.import'  => true,  'products.export'  => true,
                'suppliers.view'   => true,  'suppliers.create' => true,
                'suppliers.edit'   => true,  'suppliers.delete' => true,
                'shipping.view'    => true,  'shipping.create'  => true,
                'shipping.edit'    => true,  'shipping.delete'  => true,
                'shipping.import'  => true,
                'purchasing.view'  => true,  'purchasing.edit'  => true,
                'payments.view'    => true,  'payments.record'  => true,
                'payments.void'    => true,  'payments.export'  => true,
                'invoices.view'    => true,
                'trips.view'       => true,  'trips.edit'       => true,
                'trips.new_order'  => true,
                'reports.view'     => true,
                'promos.view'      => true,  'promos.create'    => true,
                'promos.edit'      => true,  'promos.delete'    => true,
                'settings.view'    => true,  'settings.edit'    => true,
            ],
            'finance' => [
                'orders.view'      => true,  'orders.create'    => false,
                'orders.edit'      => false, 'orders.delete'    => false,
                'orders.import'    => false, 'orders.export'    => true,
                'customers.view'   => true,  'customers.create' => false,
                'customers.edit'   => false, 'customers.delete' => false,
                'customers.import' => false, 'customers.export' => false,
                'products.view'    => true,  'products.create'  => false,
                'products.edit'    => false, 'products.delete'  => false,
                'products.import'  => false, 'products.export'  => false,
                'suppliers.view'   => true,  'suppliers.create' => false,
                'suppliers.edit'   => false, 'suppliers.delete' => false,
                'shipping.view'    => true,  'shipping.create'  => false,
                'shipping.edit'    => false, 'shipping.delete'  => false,
                'shipping.import'  => false,
                'purchasing.view'  => true,  'purchasing.edit'  => false,
                'payments.view'    => true,  'payments.record'  => true,
                'payments.void'    => true,  'payments.export'  => true,
                'invoices.view'    => true,
                'trips.view'       => true,  'trips.edit'       => false,
                'trips.new_order'  => false,
                'reports.view'     => false,
                'promos.view'      => true,  'promos.create'    => false,
                'promos.edit'      => false, 'promos.delete'    => false,
                'settings.view'    => false, 'settings.edit'    => false,
            ],
            'purchasing' => [
                'orders.view'      => true,  'orders.create'    => false,
                'orders.edit'      => false, 'orders.delete'    => false,
                'orders.import'    => false, 'orders.export'    => false,
                'customers.view'   => true,  'customers.create' => false,
                'customers.edit'   => false, 'customers.delete' => false,
                'customers.import' => false, 'customers.export' => false,
                'products.view'    => true,  'products.create'  => true,
                'products.edit'    => true,  'products.delete'  => false,
                'products.import'  => false, 'products.export'  => false,
                'suppliers.view'   => true,  'suppliers.create' => false,
                'suppliers.edit'   => false, 'suppliers.delete' => false,
                'shipping.view'    => true,  'shipping.create'  => false,
                'shipping.edit'    => false, 'shipping.delete'  => false,
                'shipping.import'  => false,
                'purchasing.view'  => true,  'purchasing.edit'  => true,
                'payments.view'    => false, 'payments.record'  => false,
                'payments.void'    => false, 'payments.export'  => false,
                'invoices.view'    => false,
                'trips.view'       => true,  'trips.edit'       => false,
                'trips.new_order'  => false,
                'reports.view'     => false,
                'promos.view'      => false, 'promos.create'    => false,
                'promos.edit'      => false, 'promos.delete'    => false,
                'settings.view'    => false, 'settings.edit'    => false,
            ],
            'staff' => [
                'orders.view'      => true,  'orders.create'    => true,
                'orders.edit'      => true,  'orders.delete'    => false,
                'orders.import'    => true,  'orders.export'    => true,
                'customers.view'   => true,  'customers.create' => true,
                'customers.edit'   => true,  'customers.delete' => false,
                'customers.import' => true,  'customers.export' => true,
                'products.view'    => true,  'products.create'  => false,
                'products.edit'    => false, 'products.delete'  => false,
                'products.import'  => false, 'products.export'  => false,
                'suppliers.view'   => true,  'suppliers.create' => false,
                'suppliers.edit'   => false, 'suppliers.delete' => false,
                'shipping.view'    => true,  'shipping.create'  => false,
                'shipping.edit'    => false, 'shipping.delete'  => false,
                'shipping.import'  => false,
                'purchasing.view'  => true,  'purchasing.edit'  => false,
                'payments.view'    => true,  'payments.record'  => true,
                'payments.void'    => false, 'payments.export'  => true,
                'invoices.view'    => true,
                'trips.view'       => true,  'trips.edit'       => false,
                'trips.new_order'  => true,
                'reports.view'     => false,
                'promos.view'      => true,  'promos.create'    => false,
                'promos.edit'      => false, 'promos.delete'    => false,
                'settings.view'    => false, 'settings.edit'    => false,
            ],
            'viewer' => [
                'orders.view'      => true,  'orders.create'    => false,
                'orders.edit'      => false, 'orders.delete'    => false,
                'orders.import'    => false, 'orders.export'    => false,
                'customers.view'   => true,  'customers.create' => false,
                'customers.edit'   => false, 'customers.delete' => false,
                'customers.import' => false, 'customers.export' => false,
                'products.view'    => true,  'products.create'  => false,
                'products.edit'    => false, 'products.delete'  => false,
                'products.import'  => false, 'products.export'  => false,
                'suppliers.view'   => true,  'suppliers.create' => false,
                'suppliers.edit'   => false, 'suppliers.delete' => false,
                'shipping.view'    => true,  'shipping.create'  => false,
                'shipping.edit'    => false, 'shipping.delete'  => false,
                'shipping.import'  => false,
                'purchasing.view'  => true,  'purchasing.edit'  => false,
                'payments.view'    => false, 'payments.record'  => false,
                'payments.void'    => false, 'payments.export'  => false,
                'invoices.view'    => true,
                'trips.view'       => true,  'trips.edit'       => false,
                'trips.new_order'  => false,
                'reports.view'     => false,
                'promos.view'      => true,  'promos.create'    => false,
                'promos.edit'      => false, 'promos.delete'    => false,
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