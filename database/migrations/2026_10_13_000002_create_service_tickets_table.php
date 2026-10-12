<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A repair/service job — mirrors TableOrder's "open tab, bill it later into
 * a real Sale" shape (see ServiceTicketController), just with the fields a
 * repair needs instead of a table/waiter: which device, whose it is, who's
 * fixing it, and whether it's a warranty claim. device_label is always a
 * real, editable string (auto-filled from product/serial when known) so a
 * ticket never depends on a product row surviving — the ticket keeps
 * reading fine even if that product is later renamed or deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_serial_id')->nullable()->constrained()->nullOnDelete();
            $table->string('device_label');
            $table->text('problem_description')->nullable();
            $table->string('technician_name')->nullable();
            $table->string('status')->default('received'); // received, in_progress, waiting_parts, ready, delivered, cancelled
            $table->boolean('is_warranty_claim')->default(false);
            $table->dateTime('received_at');
            $table->date('promised_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['shop_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_tickets');
    }
};
