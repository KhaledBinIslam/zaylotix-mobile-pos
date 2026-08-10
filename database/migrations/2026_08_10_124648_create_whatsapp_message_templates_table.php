<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A shop's own saved, reusable message snippets ("অফার", "নতুন পণ্য",
     * "বাকি রিমাইন্ডার") — distinct from a WhatsApp-approved Template
     * (Meta's own concept, created/approved in Meta Business Manager, only
     * ever referenced here by name in WhatsappBulkLog). This is the
     * app-side convenience layer on top of that: an owner writes a message
     * once and picks it again next time instead of retyping it, for
     * EITHER send type — a 'text' row prefills the free-text box, a
     * 'template' row remembers a Meta template name + language they use
     * often so they don't have to recall the exact spelling every time.
     */
    public function up(): void
    {
        Schema::create('whatsapp_message_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('label'); // owner's own name for it, e.g. "বাকি রিমাইন্ডার"
            $table->string('send_type'); // 'template' | 'text' — mirrors WhatsappBulkLog's own column
            $table->string('template_name')->nullable();
            $table->string('language_code')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_message_templates');
    }
};
