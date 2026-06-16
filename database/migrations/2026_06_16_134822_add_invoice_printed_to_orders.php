<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'invoice_printed_at')) {
                $table->timestamp('invoice_printed_at')->nullable()->after('payment_status');
            }
            if (!Schema::hasColumn('orders', 'invoice_printed_by')) {
                $table->foreignId('invoice_printed_by')->nullable()->after('invoice_printed_at')
                      ->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'invoice_printed_by')) {
                $table->dropForeign(['invoice_printed_by']);
                $table->dropColumn('invoice_printed_by');
            }
            if (Schema::hasColumn('orders', 'invoice_printed_at')) {
                $table->dropColumn('invoice_printed_at');
            }
        });
    }
};