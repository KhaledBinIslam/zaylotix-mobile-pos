<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ambassadors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('note')->nullable();
            // flat ৳ paid once per new shop brought, and % of every
            // recurring subscription payment while this ambassador is the
            // shop's currently-assigned ambassador (see
            // ambassador_shop_assignments) -- both apply platform-wide to
            // every shop this ambassador is tied to, not negotiated per shop
            $table->decimal('one_time_bonus', 10, 2)->default(0);
            $table->decimal('recurring_percent', 5, 2)->default(0);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });

        // Who's currently responsible for a shop (drives recurring %) --
        // kept as history, not a single column on shops, because this can
        // change (one ambassador leaves, another takes over the account)
        // and Khaled wants to see that history, not just the latest state.
        Schema::create('ambassador_shop_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ambassador_id')->constrained()->cascadeOnDelete();
            $table->dateTime('assigned_at');
            $table->dateTime('unassigned_at')->nullable(); // null = currently active
            $table->foreignId('assigned_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'unassigned_at']);
        });

        // Who gets credit (and the one-time bonus) for a shop signing up at
        // all -- immutable in normal use, separate from "currently assigned"
        // above, since the person who closed the deal and the person
        // servicing the account afterward aren't always the same person.
        Schema::table('shops', function (Blueprint $table) {
            $table->foreignId('brought_by_ambassador_id')->nullable()->after('parent_shop_id')->constrained('ambassadors')->nullOnDelete();
        });

        // The actual commission ledger -- one row per one-time bonus, and
        // one row per recurring payment's cut, so every taka owed/paid to
        // every ambassador is individually tracked and exportable.
        Schema::create('ambassador_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ambassador_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['one_time', 'recurring']);
            $table->decimal('amount', 10, 2);
            $table->string('month', 7)->nullable(); // 'Y-m', null for one_time
            $table->foreignId('subscription_payment_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('status', ['unpaid', 'paid'])->default('unpaid');
            $table->dateTime('paid_at')->nullable();
            $table->foreignId('paid_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['ambassador_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ambassador_commissions');
        Schema::table('shops', function (Blueprint $table) {
            $table->dropConstrainedForeignId('brought_by_ambassador_id');
        });
        Schema::dropIfExists('ambassador_shop_assignments');
        Schema::dropIfExists('ambassadors');
    }
};
