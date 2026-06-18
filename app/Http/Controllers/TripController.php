<?php

namespace App\Http\Controllers;

use App\Models\Trip;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TripController extends Controller
{
    public function index()
    {
        $uid = \Illuminate\Support\Facades\Auth::user()->isOwnDataOnly()
            ? \Illuminate\Support\Facades\Auth::id() : null;
        $trips = Trip::withCount([
                'orders' => fn($q) => $uid ? $q->where('created_by', $uid) : $q,
                'products',
            ])->latest()->paginate(15);
        return view('trips.index', compact('trips'));
    }

    public function create()
    {
        $this->adminOnly('create trips');
        return view('trips.create');
    }

    public function store(Request $request)
    {
        $this->adminOnly('create trips');
        $data = $request->validate([
            'name'           => 'required|string|max:255',
            'destination'    => 'nullable|string|max:255',
            'trip_date'      => 'nullable|date',
            'order_deadline' => 'nullable|date',
            'notes'          => 'nullable|string',
        ]);

        $data['created_by'] = Auth::id();
        $trip = Trip::create($data);

        return redirect()->route('trips.show', $trip)->with('success', 'Trip created.');
    }

    public function show(Trip $trip)
    {
        // Staff with own_data scope should only see orders THEY created
        $trip->load([
            'products.variants',
            'orders' => function ($q) {
                if (\Illuminate\Support\Facades\Auth::user()->isOwnDataOnly()) {
                    $q->where('created_by', \Illuminate\Support\Facades\Auth::id());
                }
            },
            'orders.customer',
        ]);
        $orderSummary = [
            'total'   => $trip->orders->count(),
            'unpaid'  => $trip->orders->where('payment_status', 'unpaid')->count(),
            'partial' => $trip->orders->where('payment_status', 'partial')->count(),
            'paid'    => $trip->orders->where('payment_status', 'paid')->count(),
        ];
        return view('trips.show', compact('trip', 'orderSummary'));
    }

    public function edit(Trip $trip)
    {
        $this->adminOnly('edit trips');
        return view('trips.edit', compact('trip'));
    }

    public function update(Request $request, Trip $trip)
    {
        $this->adminOnly('edit trips');
        $data = $request->validate([
            'name'           => 'required|string|max:255',
            'destination'    => 'nullable|string|max:255',
            'trip_date'      => 'nullable|date',
            'order_deadline' => 'nullable|date',
            'status'         => 'required|in:open,order_closed,purchasing,arrived,closed',
            'notes'          => 'nullable|string',
        ]);

        $trip->update($data);
        return redirect()->route('trips.show', $trip)->with('success', 'Trip updated.');
    }

    public function destroy(Trip $trip)
    {
        $this->adminOnly('delete trips');
        // Bug 7 fix: block deletion if trip has orders
        $orderCount = $trip->orders()->count();
        if ($orderCount > 0) {
            return back()->with('error', "Cannot delete trip \"{$trip->name}\" — it has {$orderCount} order(s). Close the trip instead.");
        }

        $trip->delete();
        return redirect()->route('trips.index')->with('success', 'Trip deleted.');
    }
}