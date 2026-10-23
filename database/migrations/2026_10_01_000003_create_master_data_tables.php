<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100)->unique();
            $t->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $t->string('description')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('units', function (Blueprint $t) {
            $t->id();
            $t->string('name', 50)->unique();        // Bundle, Cone, Kg
            $t->string('short_name', 15)->nullable();
            $t->timestamps();
        });

        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();
            $t->string('name', 150)->index();
            $t->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('unit_id')->constrained()->restrictOnDelete();
            $t->enum('product_type', ['raw_material', 'finished_good', 'trading'])->default('trading');
            $t->decimal('purchase_price', 15, 4)->default(0);   // default only; real cost = moving average
            $t->decimal('selling_price', 15, 4)->default(0);
            $t->decimal('default_tax_rate', 5, 2)->nullable();
            $t->decimal('min_stock', 15, 3)->default(0);
            $t->text('description')->nullable();
            $t->string('image_path')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
            // NOTE: no current_stock column on purpose. Stock = SUM over inventory_transactions.
        });

        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();
            $t->string('name', 150)->index();
            $t->string('company', 150)->nullable();
            $t->string('contact_person', 100)->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('email', 150)->nullable();
            $t->text('address')->nullable();
            $t->string('ntn', 30)->nullable();
            $t->string('strn', 30)->nullable();
            $t->string('cnic', 20)->nullable();
            $t->decimal('credit_limit', 15, 2)->default(0);
            $t->unsignedSmallInteger('payment_terms_days')->default(30);
            $t->decimal('tax_rate', 5, 2)->default(18.00);      // default sales-tax rate for this customer
            $t->boolean('is_tax_exempt')->default(false);
            $t->text('notes')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('suppliers', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();
            $t->string('name', 150)->index();
            $t->string('company', 150)->nullable();
            $t->string('contact_person', 100)->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('email', 150)->nullable();
            $t->text('address')->nullable();
            $t->string('ntn', 30)->nullable();
            $t->string('strn', 30)->nullable();
            $t->unsignedSmallInteger('payment_terms_days')->default(30);
            $t->text('notes')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('owners', function (Blueprint $t) {
            $t->id();
            $t->string('name', 150)->index();
            $t->string('phone', 30)->nullable();
            $t->string('email', 150)->nullable();
            $t->decimal('share_percent', 5, 2)->nullable();
            $t->text('notes')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('employees', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();
            $t->string('name', 150)->index();
            $t->string('designation', 100)->nullable();
            $t->string('phone', 30)->nullable();
            $t->decimal('monthly_salary', 15, 2)->default(0);
            $t->string('bank_account_no', 50)->nullable();
            $t->text('notes')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        foreach (['employees', 'owners', 'suppliers', 'customers', 'products', 'units', 'categories'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
