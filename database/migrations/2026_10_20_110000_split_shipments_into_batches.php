<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A customer's packages are not always sent on one day (3 on the 10th, 3 on the 11th, the rest on the 12th).
 *
 *   shipments         = one row per customer + trip, holding the TOTAL number of packages (package_total)
 *   shipment_batches  = one row per sending: how many packages, on which date
 *
 * Rows saved with the first version (one count + one date) become a single batch that sent everything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('packages');
            $table->date('shipped_on');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['shipment_id', 'shipped_on']);
        });

        $now = now();
        foreach (DB::table('shipments')->whereNotNull('package_count')->get() as $row) {
            DB::table('shipment_batches')->insert([
                'shipment_id' => $row->id,
                'packages'    => $row->package_count,
                'shipped_on'  => $row->shipped_on ?? substr((string) $row->created_at, 0, 10),
                'created_by'  => $row->updated_by,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }

        // Add / copy / drop instead of renameColumn, so it works on any MySQL or MariaDB version.
        Schema::table('shipments', function (Blueprint $table) {
            $table->unsignedSmallInteger('package_total')->nullable()->after('trip_id');
        });
        DB::table('shipments')->update(['package_total' => DB::raw('package_count')]);
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn(['package_count', 'shipped_on']);
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->unsignedSmallInteger('package_count')->nullable()->after('trip_id');
            $table->date('shipped_on')->nullable()->after('package_count');
        });
        foreach (DB::table('shipments')->get() as $row) {
            $first = DB::table('shipment_batches')->where('shipment_id', $row->id)->orderBy('shipped_on')->value('shipped_on');
            DB::table('shipments')->where('id', $row->id)->update(['package_count' => $row->package_total, 'shipped_on' => $first]);
        }
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn('package_total');
        });
        Schema::dropIfExists('shipment_batches');
    }
};
