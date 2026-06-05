<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // First, find and fix any existing duplicates by appending a suffix
        $duplicates = DB::table('products')
            ->select('product_code', DB::raw('COUNT(*) as cnt'))
            ->whereNotNull('product_code')
            ->where('product_code', '!=', '')
            ->groupBy('product_code')
            ->having('cnt', '>', 1)
            ->get();

        foreach ($duplicates as $dup) {
            $products = DB::table('products')
                ->where('product_code', $dup->product_code)
                ->orderBy('id')
                ->get();

            // Keep the first one as-is, rename the rest
            foreach ($products->skip(1) as $i => $product) {
                DB::table('products')
                    ->where('id', $product->id)
                    ->update(['product_code' => $dup->product_code . '_DUP' . ($i + 1)]);
            }
        }

        // Now add the unique index (only on non-null values — MySQL allows multiple NULLs)
        Schema::table('products', function (Blueprint $table) {
            $table->unique('product_code');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['product_code']);
        });
    }
};
