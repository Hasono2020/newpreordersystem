<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Expand roles
            $table->string('role', 20)->default('staff')->change();
            // Granular permission flags (nullable = inherit from role default)
            $table->json('permissions')->nullable()->after('role');
            // Profile info
            $table->string('phone', 30)->nullable()->after('permissions');
            $table->boolean('is_active')->default(true)->after('phone');
            $table->text('notes')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['permissions', 'phone', 'is_active', 'notes']);
            $table->enum('role', ['admin', 'staff'])->default('staff')->change();
        });
    }
};
