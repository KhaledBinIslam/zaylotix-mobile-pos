<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** One row per "send bulk WhatsApp" action — audit trail + owner-visible send history, not per-recipient (that volume isn't useful to browse, sent/failed counts are). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_bulk_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('send_type', ['template', 'text']);
            $table->string('template_name')->nullable();
            $table->text('message')->nullable(); // free-text body, or a copy of the template preview
            $table->unsignedInteger('recipients_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_bulk_logs');
    }
};
