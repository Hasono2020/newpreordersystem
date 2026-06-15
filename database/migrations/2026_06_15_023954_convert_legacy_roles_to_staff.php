<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // viewer/purchasing roles are removed. Convert any existing accounts:
        //  - purchasing → staff (closest equivalent with order/customer access)
        //  - viewer     → staff, but inactive (view-only users were limited;
        //                 deactivate so an admin reviews them before granting staff access)
        DB::table('users')->where('role', 'purchasing')->update(['role' => 'staff']);
        DB::table('users')->where('role', 'viewer')->update([
            'role'      => 'staff',
            'is_active' => false,
        ]);
    }

    public function down(): void
    {
        // No reliable way to restore — this is a one-way data fix.
    }
};