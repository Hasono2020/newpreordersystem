<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\ShippingArea;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $query = Customer::withCount('orders');
        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%'.$request->search.'%')
                  ->orWhere('phone', 'like', '%'.$request->search.'%');
            });
        }
        if ($request->type) {
            $query->where('type', $request->type);
        }
        $customers = $query->latest()->paginate(20)->withQueryString();
        return view('customers.index', compact('customers'));
    }

    public function create()
    {
        $shippingAreas = ShippingArea::where('is_active', true)->orderBy('name')->get();
        return view('customers.create', compact('shippingAreas'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'                     => 'required|string|max:255',
            'phone'                    => 'required|string|max:50',
            'address'                  => 'nullable|string',
            'type'                     => 'required|in:customer,reseller,selected_customer',
            'notes'                    => 'nullable|string',
            'default_shipping_area_id' => 'required|exists:shipping_areas,id',
        ]);

        $customer = Customer::create($data);
        if ($request->expectsJson()) return response()->json($customer);
        return redirect()->route('customers.show', $customer)->with('success', 'Customer added.');
    }

    public function show(Customer $customer)
    {
        $customer->load(['orders.trip', 'orders.items', 'defaultShippingArea']);
        return view('customers.show', compact('customer'));
    }

    public function edit(Customer $customer)
    {
        $shippingAreas = ShippingArea::where('is_active', true)->orderBy('name')->get();
        return view('customers.edit', compact('customer', 'shippingAreas'));
    }

    public function update(Request $request, Customer $customer)
    {
        $data = $request->validate([
            'name'                     => 'required|string|max:255',
            'phone'                    => 'required|string|max:50',
            'address'                  => 'nullable|string',
            'type'                     => 'required|in:customer,reseller,selected_customer',
            'notes'                    => 'nullable|string',
            'default_shipping_area_id' => 'required|exists:shipping_areas,id',
        ]);

        $oldAreaId = $customer->default_shipping_area_id;
        $newAreaId = $data['default_shipping_area_id'];

        $customer->update($data);

        // If shipping area changed, apply it to all this customer's orders
        // that currently have no shipping area set, then recalculate
        if ($newAreaId && $newAreaId != $oldAreaId) {
            $ordersToUpdate = $customer->orders()
                ->whereNull('shipping_area_id')
                ->with('items.product', 'items.variant', 'shippingArea')
                ->get();

            $promoSvc = app(\App\Services\PromoService::class);

            foreach ($ordersToUpdate as $order) {
                $order->update(['shipping_area_id' => $newAreaId]);
                $calc = $promoSvc->recalculate($order->fresh());
                $order->update([
                    'subtotal'             => $calc['subtotal'],
                    'discount_amount'      => $calc['discount_amount'],
                    'shipping_fee'         => $calc['shipping_fee'],
                    'shipping_discount'    => $calc['shipping_discount'],
                    'shipping_weight_gram' => $calc['shipping_weight_gram'],
                    'shipping_kg_charged'  => $calc['shipping_kg_charged'],
                    'total_amount'         => $calc['total_amount'],
                ]);
            }

            $count = $ordersToUpdate->count();
            $msg = 'Customer updated.';
            if ($count > 0) {
                $msg .= " Applied shipping area to {$count} order(s) that had none, and recalculated totals.";
            }
            return redirect()->route('customers.show', $customer)->with('success', $msg);
        }

        return redirect()->route('customers.show', $customer)->with('success', 'Customer updated.');
    }

    public function destroy(Customer $customer)
    {
        DB::transaction(function () use ($customer) {
            foreach ($customer->orders as $order) {
                $order->payments()->delete();
                $order->items()->delete();
                $order->delete();
            }
            $customer->delete();
        });
        return redirect()->route('customers.index')->with('success', 'Customer deleted.');
    }

    public function bulkDestroy(Request $request)
    {
        $request->validate([
            'action'       => 'required|in:selected,no_orders',
            'customer_ids' => 'required_if:action,selected|array',
        ]);

        DB::transaction(function () use ($request) {
            $query = Customer::query();
            if ($request->action === 'selected') {
                $query->whereIn('id', $request->customer_ids);
            } elseif ($request->action === 'no_orders') {
                $query->doesntHave('orders');
            }
            foreach ($query->with('orders.payments', 'orders.items')->get() as $customer) {
                foreach ($customer->orders as $order) {
                    $order->payments()->delete();
                    $order->items()->delete();
                    $order->delete();
                }
                $customer->delete();
            }
        });

        return redirect()->route('customers.index')->with('success', 'Customers deleted.');
    }
}
