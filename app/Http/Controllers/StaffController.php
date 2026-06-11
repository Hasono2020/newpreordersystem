<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class StaffController extends Controller
{
    private function requireAdmin(): void
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        if (!$user?->isAdmin()) abort(403, 'Admin access required.');
    }

    public function index()
    {
        $this->requireAdmin();
        $staff = User::orderBy('role')->orderBy('name')->get();
        $roles = ['admin', 'finance', 'purchasing', 'staff', 'viewer'];
        $allPermissions = array_keys(User::roleDefaults('admin'));
        return view('staff.index', compact('staff', 'roles', 'allPermissions'));
    }

    public function store(Request $request)
    {
        $this->requireAdmin();
        $data = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'role'     => 'required|in:admin,finance,purchasing,staff,viewer',
            'phone'    => 'nullable|string|max:30',
            'notes'    => 'nullable|string',
        ]);

        User::create([
            'name'      => $data['name'],
            'email'     => $data['email'],
            'password'  => Hash::make($data['password']),
            'role'      => $data['role'],
            'phone'     => $data['phone'] ?? null,
            'notes'     => $data['notes'] ?? null,
            'is_active' => true,
        ]);

        return redirect()->route('staff.index')->with('success', 'Account created for '.$data['name'].'.');
    }

    public function update(Request $request, User $staff)
    {
        $this->requireAdmin();
        $data = $request->validate([
            'name'      => 'required|string|max:255',
            'email'     => 'required|email|unique:users,email,'.$staff->id,
            'role'      => 'required|in:admin,finance,purchasing,staff,viewer',
            'phone'     => 'nullable|string|max:30',
            'is_active' => 'boolean',
            'notes'     => 'nullable|string',
            'permissions' => 'nullable|array',
        ]);

        // Build custom permission overrides (only store true differences from role defaults)
        $customPerms = null;
        if ($request->has('permissions')) {
            $roleDefaults = User::roleDefaults($data['role']);
            $overrides    = [];

            // Check ALL known permissions (role defaults + any extra submitted)
            $allPerms = array_unique(array_merge(
                array_keys($roleDefaults),
                array_keys($request->permissions ?? [])
            ));

            foreach ($allPerms as $perm) {
                $default   = (bool) ($roleDefaults[$perm] ?? false);
                $submitted = (bool) isset($request->permissions[$perm]);
                // Only store if it genuinely differs from role default
                if ($submitted !== $default) {
                    $overrides[$perm] = $submitted;
                }
            }
            $customPerms = empty($overrides) ? null : $overrides;
        }

        $staff->update([
            'name'        => $data['name'],
            'email'       => $data['email'],
            'role'        => $data['role'],
            'phone'       => $data['phone'] ?? null,
            'is_active'   => $request->boolean('is_active', true),
            'notes'       => $data['notes'] ?? null,
            'permissions' => $customPerms,
        ]);

        if ($request->filled('password')) {
            $request->validate(['password' => 'min:8|confirmed']);
            $staff->update(['password' => Hash::make($request->password)]);
        }

        return redirect()->route('staff.index')->with('success', $staff->name.' updated.');
    }

    public function destroy(User $staff)
    {
        $this->requireAdmin();
        /** @var \App\Models\User $authUser */
        $authUser = Auth::user();
        if ($staff->id === $authUser->id) {
            return back()->with('error', 'You cannot delete your own account.');
        }
        $staff->delete();
        return redirect()->route('staff.index')->with('success', 'Account deleted.');
    }
}