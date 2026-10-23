<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Money received at the moment of sale. Later receipts are Receipt documents with allocations.
        Schema::table('sales', function (Blueprint $t) {
            $t->decimal('received_now', 15, 2)->default(0)->after('cogs_total');
            $t->foreignId('received_into_account_id')->nullable()->after('received_now')->constrained('accounts')->restrictOnDelete();
        });

        // Cost of goods actually taken out of stock for the line (so returns put goods back at the original cost).
        Schema::table('sale_items', function (Blueprint $t) {
            $t->decimal('cogs_amount', 15, 2)->default(0)->after('unit_cost');
        });

        // Cash refunded to the customer when the return is made; the rest is a credit note against what they owe.
        Schema::table('sales_returns', function (Blueprint $t) {
            $t->decimal('refund_now', 15, 2)->default(0)->after('total');
            $t->foreignId('refund_account_id')->nullable()->after('refund_now')->constrained('accounts')->restrictOnDelete();
        });

        Schema::table('sales_return_items', function (Blueprint $t) {
            $t->decimal('cogs_amount', 15, 2)->default(0)->after('unit_cost');
        });
    }

    public function down(): void
    {
        Schema::table('sales_return_items', fn (Blueprint $t) => $t->dropColumn('cogs_amount'));
        Schema::table('sales_returns', function (Blueprint $t) {
            $t->dropConstrainedForeignId('refund_account_id');
            $t->dropColumn('refund_now');
        });
        Schema::table('sale_items', fn (Blueprint $t) => $t->dropColumn('cogs_amount'));
        Schema::table('sales', function (Blueprint $t) {
            $t->dropConstrainedForeignId('received_into_account_id');
            $t->dropColumn('received_now');
        });
    }
};
