<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class StaffController extends Controller
{
    private function requireAdmin(): void
    {
        if (!auth()->user()?->isAdmin()) {
            abort(403, 'Admin access required.');
        }
    }

    public function index()
    {
        $this->requireAdmin();
        $staff = User::orderBy('role')->orderBy('name')->get();
        return view('staff.index', compact('staff'));
    }

    public function store(Request $request)
    {
        $this->requireAdmin();

        $data = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'role'     => 'required|in:admin,staff',
        ]);

        User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => Hash::make($data['password']),
            'role'     => $data['role'],
        ]);

        return redirect()->route('staff.index')->with('success', 'Account created for '.$data['name'].'.');
    }

    public function update(Request $request, User $staff)
    {
        $this->requireAdmin();

        $data = $request->validate([
            'name'  => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,'.$staff->id,
            'role'  => 'required|in:admin,staff',
        ]);

        $staff->update($data);

        if ($request->filled('password')) {
            $request->validate(['password' => 'min:8|confirmed']);
            $staff->update(['password' => Hash::make($request->password)]);
        }

        return redirect()->route('staff.index')->with('success', $staff->name.' updated.');
    }

    public function destroy(User $staff)
    {
        $this->requireAdmin();

        if ($staff->id === auth()->id()) {
            return back()->with('error', 'You cannot delete your own account.');
        }
        $staff->delete();
        return redirect()->route('staff.index')->with('success', 'Account deleted.');
    }
}
