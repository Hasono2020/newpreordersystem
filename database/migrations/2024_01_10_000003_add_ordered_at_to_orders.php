<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('ordered_at')->nullable()->after('created_by');
        });

        // Backfill: set ordered_at = created_at for all existing orders
        DB::statement('UPDATE orders SET ordered_at = created_at WHERE ordered_at IS NULL');
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('ordered_at');
        });
    }
};
