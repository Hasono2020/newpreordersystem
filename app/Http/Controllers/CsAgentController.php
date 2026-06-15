<?php

namespace App\Http\Controllers;

use App\Models\CsAgent;
use Illuminate\Http\Request;

class CsAgentController extends Controller
{
    public function index(Request $request)
    {
        $perPage = in_array((int)$request->per_page, [20, 50, 100, 200]) ? (int)$request->per_page : 20;
        $query   = CsAgent::withCount('orders');
        if ($request->search) {
            $query->where('name', 'like', '%'.$request->search.'%')
                  ->orWhere('handle', 'like', '%'.$request->search.'%');
        }
        $agents = $query->orderBy('name')->paginate($perPage)->withQueryString();
        return view('cs_agents.index', compact('agents', 'perPage'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'      => 'required|string|max:255',
            'handle'    => 'nullable|string|max:255',
            'notes'     => 'nullable|string',
            'is_active' => 'boolean',
        ]);
        $data['is_active'] = $request->boolean('is_active', true);
        $agent = CsAgent::create($data);

        if ($request->expectsJson()) return response()->json($agent);
        return redirect()->route('cs-agents.index')->with('success', 'CS agent added.');
    }

    public function update(Request $request, CsAgent $csAgent)
    {
        $data = $request->validate([
            'name'      => 'required|string|max:255',
            'handle'    => 'nullable|string|max:255',
            'notes'     => 'nullable|string',
            'is_active' => 'boolean',
        ]);
        $data['is_active'] = $request->boolean('is_active');
        $csAgent->update($data);
        return redirect()->route('cs-agents.index')->with('success', 'CS agent updated.');
    }

    public function destroy(CsAgent $csAgent)
    {
        // Nullify FK on orders before deleting (keeps order history intact)
        $csAgent->orders()->update(['cs_agent_id' => null]);
        $csAgent->delete();
        return redirect()->route('cs-agents.index')->with('success', 'CS agent deleted.');
    }
}