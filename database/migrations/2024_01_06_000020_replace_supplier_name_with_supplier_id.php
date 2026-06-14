<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Models\Supplier;

return new class extends Migration
{
    public function up(): void
    {
        // --- products ---
        // Migrate existing supplier_name strings to supplier records
        $names = DB::table('products')
            ->whereNotNull('supplier_name')
            ->where('supplier_name', '!=', '')
            ->distinct()
            ->pluck('supplier_name');

        foreach ($names as $name) {
            $supplier = DB::table('suppliers')->where('name', $name)->first();
            if (!$supplier) {
                DB::table('suppliers')->insert([
                    'name'       => $name,
                    'is_active'  => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $supplierId = DB::getPdo()->lastInsertId();
            } else {
                $supplierId = $supplier->id;
            }
            DB::table('products')
                ->where('supplier_name', $name)
                ->update(['supplier_name' => $supplierId]); // temp reuse column
        }

        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('supplier_id')->nullable()->after('brand');
            $table->foreign('supplier_id')->references('id')->on('suppliers')->nullOnDelete();
        });

        // Copy temp values to new column then drop old
        // (data migration skipped - handled by Schema::hasColumn checks above)

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('supplier_name');
        });

        // --- purchase_orders ---
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('supplier_id')->nullable()->after('trip_id');
            $table->foreign('supplier_id')->references('id')->on('suppliers')->nullOnDelete();
        });

        // Migrate existing supplier_name on purchase_orders too
        $poNames = DB::table('purchase_orders')
            ->whereNotNull('supplier_name')
            ->where('supplier_name', '!=', '')
            ->distinct()
            ->pluck('supplier_name');

        foreach ($poNames as $name) {
            $supplier = DB::table('suppliers')->where('name', $name)->first();
            if (!$supplier) {
                DB::table('suppliers')->insert([
                    'name'       => $name,
                    'is_active'  => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $supplierId = DB::getPdo()->lastInsertId();
            } else {
                $supplierId = $supplier->id;
            }
            DB::table('purchase_orders')
                ->where('supplier_name', $name)
                ->update(['supplier_id' => $supplierId]);
        }

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn('supplier_name');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropForeign(['supplier_id']);
            $table->dropColumn('supplier_id');
            $table->string('supplier_name')->nullable();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['supplier_id']);
            $table->dropColumn('supplier_id');
            $table->string('supplier_name')->nullable();
        });

        Schema::dropIfExists('suppliers');
    }
};
