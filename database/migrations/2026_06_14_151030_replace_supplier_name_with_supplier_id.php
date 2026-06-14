<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // This migration moves old supplier_name strings to a supplier_id FK.
        // On a fresh database (tests, new installs) supplier_name doesn't exist
        // yet, so we skip the data migration entirely and just add the columns.

        $hasSupplierName = Schema::hasColumn('products', 'supplier_name');

        if ($hasSupplierName) {
            // ── Data migration (production upgrade only) ──────────────────
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
                    ->update(['supplier_name' => $supplierId]);
            }
        }

        // ── Schema changes (always run) ────────────────────────────────
        if (!Schema::hasColumn('products', 'supplier_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->unsignedBigInteger('supplier_id')->nullable()->after('brand');
                $table->foreign('supplier_id')->references('id')->on('suppliers')->nullOnDelete();
            });
        }

        if ($hasSupplierName) {
            // Copy numeric supplier_name values → supplier_id
            DB::table('products')
                ->whereNotNull('supplier_name')
                ->where('supplier_name', '!=', '')
                ->get(['id', 'supplier_name'])
                ->each(function ($row) {
                    if (is_numeric($row->supplier_name)) {
                        DB::table('products')
                            ->where('id', $row->id)
                            ->update(['supplier_id' => (int) $row->supplier_name]);
                    }
                });

            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('supplier_name');
            });
        }

        // ── purchase_orders ───────────────────────────────────────────
        if (!Schema::hasColumn('purchase_orders', 'supplier_id')) {
            Schema::table('purchase_orders', function (Blueprint $table) {
                $table->unsignedBigInteger('supplier_id')->nullable()->after('trip_id');
                $table->foreign('supplier_id')->references('id')->on('suppliers')->nullOnDelete();
            });
        }

        if (Schema::hasColumn('purchase_orders', 'supplier_name')) {
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
    }
};