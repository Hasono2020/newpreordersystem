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
        // Suggested next batch number — editable, not enforced. Leaving it
        // blank keeps this trip on the old random order-number scheme.
        $suggestedBatchNumber = (Trip::max('batch_number') ?? 0) + 1;
        return view('trips.create', compact('suggestedBatchNumber'));
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
            'batch_number'   => 'nullable|integer|min:1|unique:trips,batch_number',
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
        $suggestedBatchNumber = (Trip::where('id', '!=', $trip->id)->max('batch_number') ?? 0) + 1;
        return view('trips.edit', compact('trip', 'suggestedBatchNumber'));
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
            'batch_number'   => 'nullable|integer|min:1|unique:trips,batch_number,' . $trip->id,
        ]);

        // Once orders have already been numbered under this trip's batch,
        // changing the batch number would leave their existing order
        // numbers referencing a batch that no longer matches — block it
        // rather than silently create that mismatch.
        if ($trip->next_order_seq > 0 && (int) ($data['batch_number'] ?? 0) !== (int) $trip->batch_number) {
            return back()->withInput()->with('error',
                "Can't change the batch number — {$trip->next_order_seq} order(s) have already been numbered under Batch {$trip->batch_number}.");
        }

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

        // The products.trip_id FK is ON DELETE CASCADE, so deleting the trip
        // wipes its products at the DB level — collect image files first so
        // they don't become orphans in storage.
        $imagePaths = $trip->products()->whereNotNull('image')->pluck('image');

        $trip->delete();

        if ($imagePaths->isNotEmpty()) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($imagePaths->all());
        }
        return redirect()->route('trips.index')->with('success', 'Trip deleted.');
    }
}