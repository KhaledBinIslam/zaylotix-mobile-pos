<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_activity_logs', function (Blueprint $table) {
            $table->id();
            // nullOnDelete, not cascade — an admin account being removed
            // later must not erase the record of what they did
            $table->foreignId('admin_id')->nullable()->constrained()->nullOnDelete();

            $table->string('action'); // e.g. 'shop.create', 'shop.delete', 'impersonate.start'
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('description');
            $table->json('meta')->nullable();

            $table->timestamp('created_at')->nullable(); // append-only — no updated_at

            $table->index('created_at');
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_activity_logs');
    }
};
