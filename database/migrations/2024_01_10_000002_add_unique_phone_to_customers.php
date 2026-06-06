<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Fix any existing duplicates first — keep the oldest, append suffix to others
        $duplicates = DB::table('customers')
            ->select('phone', DB::raw('COUNT(*) as cnt'))
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->groupBy('phone')
            ->having('cnt', '>', 1)
            ->get();

        foreach ($duplicates as $dup) {
            $customers = DB::table('customers')
                ->where('phone', $dup->phone)
                ->orderBy('id')
                ->get();

            foreach ($customers->skip(1) as $i => $customer) {
                DB::table('customers')
                    ->where('id', $customer->id)
                    ->update(['phone' => $dup->phone . '_DUP' . ($i + 1)]);
            }
        }

        // Also fix imported placeholder phones like 'imported-123'
        // that might collide — make them null instead
        DB::table('customers')
            ->where('phone', 'like', 'imported-%')
            ->update(['phone' => null]);

        Schema::table('customers', function (Blueprint $table) {
            // Unique on phone, but allow multiple NULLs (MySQL allows this)
            $table->unique('phone');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['phone']);
        });
    }
};
