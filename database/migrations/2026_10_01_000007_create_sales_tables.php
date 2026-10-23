<?php

use App\Support\DocumentColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $t) {
            $t->id();
            $t->string('invoice_no', 30)->unique();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->date('sale_date')->index();
            $t->date('due_date')->nullable();
            $t->string('gate_pass_no', 30)->nullable();         // "OGP" on the printed invoice
            $t->decimal('subtotal', 15, 2)->default(0);         // value ex-tax, after line discounts
            $t->decimal('discount_total', 15, 2)->default(0);
            $t->decimal('tax_total', 15, 2)->default(0);
            $t->decimal('total', 15, 2)->default(0);            // value inc. tax
            $t->decimal('cogs_total', 15, 2)->default(0);       // filled when posted
            // Snapshots so an old invoice never changes when the customer record is edited
            $t->string('buyer_name', 150)->nullable();
            $t->text('buyer_address')->nullable();
            $t->string('buyer_ntn', 30)->nullable();
            $t->string('buyer_strn', 30)->nullable();
            $t->text('notes')->nullable();
            DocumentColumns::add($t);
        });

        Schema::create('sale_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->string('description', 200)->nullable();
            $t->decimal('qty', 15, 3);
            $t->decimal('unit_price', 15, 4);
            $t->decimal('discount', 15, 2)->default(0);
            $t->decimal('tax_rate', 5, 2)->default(0);          // editable per line; defaults from the customer
            $t->decimal('tax_amount', 15, 2)->default(0);
            $t->decimal('line_total', 15, 2);                   // value inc. tax
            $t->decimal('unit_cost', 15, 4)->nullable();        // moving-average cost at posting (COGS)
            $t->timestamps();
        });

        Schema::create('sales_returns', function (Blueprint $t) {
            $t->id();
            $t->string('return_no', 30)->unique();
            $t->foreignId('sale_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->date('return_date')->index();
            $t->decimal('subtotal', 15, 2)->default(0);
            $t->decimal('tax_total', 15, 2)->default(0);
            $t->decimal('total', 15, 2)->default(0);
            $t->string('reason', 150)->nullable();
            $t->text('notes')->nullable();
            DocumentColumns::add($t);
        });

        Schema::create('sales_return_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sales_return_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->decimal('qty', 15, 3);
            $t->decimal('unit_price', 15, 4);
            $t->decimal('unit_cost', 15, 4)->nullable();
            $t->decimal('tax_rate', 5, 2)->default(0);
            $t->decimal('tax_amount', 15, 2)->default(0);
            $t->decimal('line_total', 15, 2);
            $t->timestamps();
        });

        // One row per print type per sale; print_count increments on reprint
        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sale_id')->constrained()->restrictOnDelete();
            $t->enum('copy_type', ['original', 'duplicate', 'cancelled']);
            $t->unsignedInteger('print_count')->default(1);
            $t->foreignId('printed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('printed_at')->useCurrent();
            $t->timestamps();
            $t->unique(['sale_id', 'copy_type']);
        });
    }

    public function down(): void
    {
        foreach (['invoices', 'sales_return_items', 'sales_returns', 'sale_items', 'sales'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
