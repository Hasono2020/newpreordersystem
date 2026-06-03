<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->integer('min_items')->default(1); // minimum items to qualify
            $table->decimal('discount_per_item', 15, 2)->default(0); // discount per item (reseller style)
            $table->decimal('discount_flat', 15, 2)->default(0); // flat discount on order
            $table->decimal('max_shipping_subsidy', 15, 2)->default(0); // free shipping max
            $table->json('eligible_customer_types')->nullable(); // ["customer","reseller","selected_customer"] or null=all
            $table->foreignId('trip_id')->nullable()->constrained()->onDelete('cascade'); // null=global
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_rules');
    }
};
