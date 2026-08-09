<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A logged, instant stock move between two branches of the same business
 * (warehouse->branch, branch->branch, branch->warehouse -- all the same
 * mechanism, see StockTransferController). Each branch keeps its own
 * independent Product row (see CatalogSync's docblock), so a transfer
 * records BOTH sides' product id -- they're matched by barcode/name at
 * transfer time, same matching CatalogSync already uses, but the destination
 * product id is stored explicitly here so this row means something even
 * after either product is later renamed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            // "shop_id" (BelongsToTenant's stamped column) is the branch this
            // transfer is filed under for tenant-scoped listing -- always
            // equal to from_shop_id, kept as its own column so this model can
            // use the normal shop_id global scope like everything else
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('to_shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('from_product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('to_product_id')->constrained('products')->cascadeOnDelete();
            $table->string('product_name'); // snapshot, survives either side being renamed/deleted later
            $table->decimal('qty', 10, 3);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfers');
    }
};
