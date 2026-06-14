<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('payments', function (Blueprint $table) {
            $table->enum('verification_status', ['unverified','verified','disputed'])
                  ->default('unverified')->after('void_reason');
            $table->foreignId('verified_by')->nullable()->constrained('users')->after('verification_status');
            $table->timestamp('verified_at')->nullable()->after('verified_by');
            $table->text('dispute_note')->nullable()->after('verified_at');
        });
    }
    public function down(): void {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['verified_by']);
            $table->dropColumn(['verification_status','verified_by','verified_at','dispute_note']);
        });
    }
};