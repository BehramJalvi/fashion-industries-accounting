<?php

use App\Support\DocumentColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_orders', function (Blueprint $t) {
            $t->id();
            $t->string('order_no', 30)->unique();
            $t->date('order_date')->index();
            $t->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();            // job work for a customer
            $t->foreignId('processor_supplier_id')->nullable()->constrained('suppliers')->restrictOnDelete(); // e.g. a dyeing house
            $t->text('notes')->nullable();
            DocumentColumns::add($t);
        });

        Schema::create('production_inputs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('production_order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();   // raw material consumed
            $t->decimal('qty', 15, 3);
            $t->decimal('unit_cost', 15, 4)->nullable();                      // moving average at posting
            $t->timestamps();
        });

        Schema::create('production_outputs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('production_order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();   // finished goods (thread)
            $t->decimal('qty', 15, 3);
            $t->decimal('unit_cost', 15, 4)->nullable();                      // allocated input cost
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['production_outputs', 'production_inputs', 'production_orders'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
