<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Money paid at the moment of purchase (cash / bank). Later payments are Payment documents with allocations.
        Schema::table('purchases', function (Blueprint $t) {
            $t->decimal('paid_now', 15, 2)->default(0)->after('total');
            $t->foreignId('paid_from_account_id')->nullable()->after('paid_now')->constrained('accounts')->restrictOnDelete();
        });

        // Cash refunded by the supplier at the time of the return; the rest is credited against what we owe.
        Schema::table('purchase_returns', function (Blueprint $t) {
            $t->decimal('refund_now', 15, 2)->default(0)->after('total');
            $t->foreignId('refund_account_id')->nullable()->after('refund_now')->constrained('accounts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_returns', function (Blueprint $t) {
            $t->dropConstrainedForeignId('refund_account_id');
            $t->dropColumn('refund_now');
        });
        Schema::table('purchases', function (Blueprint $t) {
            $t->dropConstrainedForeignId('paid_from_account_id');
            $t->dropColumn('paid_now');
        });
    }
};
