<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Trip;
use App\Models\Product;

class DashboardController extends Controller
{
    public function index()
    {
        $stats = [
            'trips_open' => Trip::where('status', 'open')->count(),
            'orders_today' => Order::whereDate('created_at', today())->count(),
            'unpaid_orders' => Order::where('payment_status', 'unpaid')->count(),
            'total_customers' => Customer::count(),
        ];

        $recentOrders = Order::with('customer', 'trip')
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
