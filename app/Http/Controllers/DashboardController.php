<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Trip;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function index()
    {
        $user      = Auth::user();
        $ownOnly   = $user->isOwnDataOnly();

        $orderBase = Order::when($ownOnly, fn($q) => $q->where('created_by', $user->id));

        $stats = [
            'trips_open'      => Trip::where('status', 'open')->count(),
            'orders_today'    => (clone $orderBase)->whereDate('created_at', today())->count(),
            'unpaid_orders'   => (clone $orderBase)->where('payment_status', 'unpaid')->count(),
            'total_customers' => Customer::count(), // customers always shared
        ];

        $recentOrders = (clone $orderBase)
            ->with('customer', 'trip')
            ->latest()
            ->limit(10)
            ->get();

        $activeTrips = Trip::whereIn('status', ['open', 'purchasing'])
            ->withCount('orders')
            ->latest()
            ->limit(5)
            ->get();

        return view('dashboard.index', compact('stats', 'recentOrders', 'activeTrips'));
    }
}