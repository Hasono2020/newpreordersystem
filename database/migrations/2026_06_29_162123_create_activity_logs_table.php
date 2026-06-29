<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            // Who did it. Nullable + nullOnDelete so logs survive even if the user is later removed.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name');          // snapshot of the name at time of action (survives user rename/deletion)
            // What happened — a stable machine key like 'payment.voided', 'order.deleted'
            $table->string('action', 50)->index();
            // Human-readable sentence describing the action
            $table->string('description', 500);
            // What it was done to (polymorphic-ish, but kept simple): e.g. 'order' / 'payment', + id
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            // Optional before/after snapshot (used for order edits). null for action-only events.
            $table->json('changes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};