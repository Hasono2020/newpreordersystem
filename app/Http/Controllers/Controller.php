<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Support\Facades\Auth;

abstract class Controller
{
    protected function adminOnly(string $action = 'perform this action'): void
    {
        if (!Auth::user()?->isAdmin()) {
            abort(403, "Only admins can {$action}.");
        }
    }

    /**
     * Check if current user can edit/delete a specific order.
     * Admin: any order.
     * Staff: only orders they created.
     * Finance/Purchasing/Viewer: never.
     */
    protected function authorizeOrderWrite(Order $order, string $action = 'edit'): void
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        if ($user->isAdmin()) return; // admin can do anything

        $perm = $action === 'delete' ? 'orders.delete' : 'orders.edit';
        if (!$user->hasPermission($perm)) {
            abort(403, "You don't have permission to {$action} orders.");
        }

        // Staff can only edit/delete their own orders
        if ($user->role === 'staff' && $order->created_by !== $user->id) {
            abort(403, "You can only {$action} orders you created.");
        }
    }
}