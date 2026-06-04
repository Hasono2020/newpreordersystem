<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;

class SettingsController extends Controller
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
        return view('settings.index', [
            'store_name'    => Setting::get('store_name', 'PreOrder System'),
            'store_tagline' => Setting::get('store_tagline', 'Overseas Shopping Service'),
            'store_phone'   => Setting::get('store_phone'),
            'store_address' => Setting::get('store_address'),
        ]);
    }

    public function update(Request $request)
    {
        $this->requireAdmin();

        $data = $request->validate([
            'store_name'    => 'required|string|max:100',
            'store_tagline' => 'nullable|string|max:200',
            'store_phone'   => 'nullable|string|max:50',
            'store_address' => 'nullable|string|max:500',
        ]);

        foreach ($data as $key => $value) {
            Setting::set($key, $value ?? '');
        }

        return back()->with('success', 'Store settings saved.');
    }
}
