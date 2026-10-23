<?php

use App\Support\DocumentColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $t) {
            $t->id();
            $t->string('purchase_no', 30)->unique();
            $t->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();          // null = walk-in / cash buy
            $t->foreignId('funded_by_employee_id')->nullable()->constrained('employees')->restrictOnDelete(); // employee paid from own pocket
            $t->date('purchase_date')->index();
            $t->date('due_date')->nullable();
            $t->string('supplier_ref', 60)->nullable();
            $t->decimal('subtotal', 15, 2)->default(0);
            $t->decimal('discount_total', 15, 2)->default(0);
            $t->decimal('tax_total', 15, 2)->default(0);
            $t->decimal('total', 15, 2)->default(0);
            $t->text('notes')->nullable();
            DocumentColumns::add($t);
        });

        Schema::create('purchase_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->decimal('qty', 15, 3);
            $t->decimal('unit_cost', 15, 4);
            $t->decimal('discount', 15, 2)->default(0);
            $t->decimal('tax_rate', 5, 2)->default(0);
            $t->decimal('tax_amount', 15, 2)->default(0);
            $t->decimal('line_total', 15, 2);
            $t->timestamps();
        });

        Schema::create('purchase_returns', function (Blueprint $t) {
            $t->id();
            $t->string('return_no', 30)->unique();
            $t->foreignId('purchase_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $t->date('return_date')->index();
            $t->decimal('subtotal', 15, 2)->default(0);
            $t->decimal('tax_total', 15, 2)->default(0);
            $t->decimal('total', 15, 2)->default(0);
            $t->string('reason', 150)->nullable();
            $t->text('notes')->nullable();
            DocumentColumns::add($t);
        });

        Schema::create('purchase_return_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_return_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->decimal('qty', 15, 3);
            $t->decimal('unit_cost', 15, 4);
            $t->decimal('tax_rate', 5, 2)->default(0);
            $t->decimal('tax_amount', 15, 2)->default(0);
            $t->decimal('line_total', 15, 2);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['purchase_return_items', 'purchase_returns', 'purchase_items', 'purchases'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
