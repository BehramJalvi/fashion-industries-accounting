<?php

use App\Support\DocumentColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_transactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->date('txn_date');
            $t->enum('txn_type', [
                'opening', 'purchase', 'purchase_return', 'sale', 'sale_return',
                'adjustment_in', 'adjustment_out', 'production_consume', 'production_output', 'reversal',
            ]);
            $t->string('source_type', 50)->nullable();
            $t->unsignedBigInteger('source_id')->nullable();
            $t->decimal('qty_in', 15, 3)->default(0);
            $t->decimal('qty_out', 15, 3)->default(0);
            $t->decimal('unit_cost', 15, 4)->default(0);        // cost of THIS movement
            $t->decimal('total_cost', 15, 2)->default(0);
            $t->decimal('balance_qty', 15, 3);                  // running balance after this row
            $t->decimal('avg_cost_after', 15, 4);               // moving weighted-average cost after this row
            $t->decimal('balance_value', 15, 2);                // balance_qty * avg_cost_after
            $t->foreignId('reverses_id')->nullable()->constrained('inventory_transactions')->restrictOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['product_id', 'txn_date', 'id']);
            $t->index(['source_type', 'source_id']);
        });

        Schema::create('stock_adjustments', function (Blueprint $t) {
            $t->id();
            $t->string('adjustment_no', 30)->unique();
            $t->date('adjustment_date')->index();
            $t->string('reason', 150);
            $t->text('notes')->nullable();
            DocumentColumns::add($t);
        });

        Schema::create('stock_adjustment_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('stock_adjustment_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->enum('direction', ['in', 'out']);
            $t->decimal('qty', 15, 3);
            $t->decimal('unit_cost', 15, 4)->nullable();        // required for 'in'; 'out' uses the moving average
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustment_items');
        Schema::dropIfExists('stock_adjustments');
        Schema::dropIfExists('inventory_transactions');
    }
};
