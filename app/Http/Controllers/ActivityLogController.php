<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        // Admin-only oversight data
        if (!Auth::user()->isAdmin()) {
            abort(403, 'Only admins can view the activity log.');
        }

        $query = ActivityLog::with('user')->latest();

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }
        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }
        if ($request->filled('search')) {
            $query->where('description', 'like', '%' . $request->search . '%');
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $logs = $query->paginate(50)->withQueryString();

        $staffList = User::orderBy('name')->get(['id', 'name']);
        $actionTypes = [
            'payment.recorded'     => 'Payment recorded',
            'payment.voided'       => 'Payment voided',
            'payment.batch_voided' => 'Payment batch voided',
            'order.updated'        => 'Order edited',
            'order.deleted'        => 'Order deleted',
            'order.bulk_deleted'   => 'Orders bulk-deleted',
        ];

        return view('activity-logs.index', compact('logs', 'staffList', 'actionTypes'));
    }
}