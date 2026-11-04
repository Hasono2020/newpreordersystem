<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A fourth product status, "deactivated": a product that has sold out (or is otherwise off
 * sale) and must no longer be offered when creating an order or adding an item. Orders that
 * already contain it are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE products MODIFY status ENUM('active','closed','arrived','deactivated') NOT NULL DEFAULT 'active'");
        } else {
            // Other databases (e.g. SQLite): a plain string column; the app validates the values.
            Schema::table('products', fn (Blueprint $table) => $table->string('status', 20)->default('active')->change());
        }
    }

    public function down(): void
    {
        // Nothing in the old enum can hold "deactivated", so those products go back to "closed".
        DB::table('products')->where('status', 'deactivated')->update(['status' => 'closed']);

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE products MODIFY status ENUM('active','closed','arrived') NOT NULL DEFAULT 'active'");
        }
    }
};
