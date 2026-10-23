<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->decimal('processing_fee', 15, 2)->default(0)->after('processor_supplier_id');
            $table->foreignId('paid_from_account_id')->nullable()->after('processing_fee')->constrained('accounts')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropForeign(['paid_from_account_id']);
            $table->dropColumn(['paid_from_account_id', 'processing_fee']);
        });
    }
};
