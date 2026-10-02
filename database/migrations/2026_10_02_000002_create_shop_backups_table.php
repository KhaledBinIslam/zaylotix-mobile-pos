<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks a shop owner's own self-service data backups (App\Jobs\
 * GenerateShopBackupJob) — a complete SQL dump or Excel workbook of
 * everything that belongs to their shop, generated in the background so
 * requesting one never blocks the live POS. Distinct from the admin-only,
 * whole-platform `zaylotix:backup`/Admin\BackupController pair — this is
 * one shop's own copy of their own data, nothing else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_backups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('format'); // 'sql' or 'xlsx'
            $table->string('status')->default('pending'); // pending, processing, done, failed
            $table->string('file_path')->nullable();
            $table->text('failed_reason')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_backups');
    }
};
