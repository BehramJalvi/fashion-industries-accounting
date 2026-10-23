<?php

use App\Support\DocumentColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipts', function (Blueprint $t) {
            $t->id();
            $t->string('receipt_no', 30)->unique();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->date('receipt_date')->index();
            $t->enum('method', ['cash', 'bank', 'cheque', 'online'])->default('cash');
            $t->foreignId('account_id')->constrained('accounts')->restrictOnDelete(); // cash or bank account
            $t->decimal('amount', 15, 2);
            $t->string('reference', 60)->nullable();            // cheque / transaction no.
            $t->text('notes')->nullable();
            DocumentColumns::add($t);
        });

        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->string('payment_no', 30)->unique();
            $t->enum('payee_type', ['supplier', 'employee']);
            $t->unsignedBigInteger('payee_id');
            $t->date('payment_date')->index();
            $t->enum('method', ['cash', 'bank', 'cheque', 'online'])->default('cash');
            $t->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $t->decimal('amount', 15, 2);
            $t->string('reference', 60)->nullable();
            $t->text('notes')->nullable();
            DocumentColumns::add($t);
            $t->index(['payee_type', 'payee_id']);
        });

        // Replaces the workbook's "retype the invoice number as Reference No." matching
        Schema::create('payment_allocations', function (Blueprint $t) {
            $t->id();
            $t->enum('document_type', ['receipt', 'payment']);
            $t->unsignedBigInteger('document_id');
            $t->enum('target_type', ['sale', 'purchase']);
            $t->unsignedBigInteger('target_id');
            $t->decimal('amount', 15, 2);
            $t->boolean('is_active')->default(true);            // set false when the receipt/payment is cancelled
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['document_type', 'document_id']);
            $t->index(['target_type', 'target_id']);
        });

        Schema::create('expenses', function (Blueprint $t) {
            $t->id();
            $t->string('expense_no', 30)->unique();
            $t->date('expense_date')->index();
            $t->foreignId('expense_account_id')->constrained('accounts')->restrictOnDelete();
            // Paid now from cash/bank, OR left null to credit the payee's payable (e.g. salary payable to an employee)
            $t->foreignId('paid_from_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $t->string('payee_type', 20)->nullable();           // supplier | employee
            $t->unsignedBigInteger('payee_id')->nullable();
            $t->decimal('amount', 15, 2);
            $t->decimal('tax_amount', 15, 2)->default(0);
            $t->string('description', 255);
            $t->text('notes')->nullable();
            DocumentColumns::add($t);
            $t->index(['payee_type', 'payee_id']);
        });

        Schema::create('fund_transfers', function (Blueprint $t) {
            $t->id();
            $t->string('transfer_no', 30)->unique();
            $t->date('transfer_date')->index();
            $t->foreignId('from_account_id')->constrained('accounts')->restrictOnDelete();
            $t->foreignId('to_account_id')->constrained('accounts')->restrictOnDelete();
            $t->decimal('amount', 15, 2);
            $t->string('reference', 60)->nullable();
            $t->text('notes')->nullable();
            DocumentColumns::add($t);
        });

        Schema::create('owner_transactions', function (Blueprint $t) {
            $t->id();
            $t->string('txn_no', 30)->unique();
            $t->foreignId('owner_id')->constrained()->restrictOnDelete();
            $t->enum('txn_type', ['capital', 'drawing']);
            $t->date('txn_date')->index();
            $t->foreignId('account_id')->constrained('accounts')->restrictOnDelete(); // cash or bank
            $t->decimal('amount', 15, 2);
            $t->text('notes')->nullable();
            DocumentColumns::add($t);
        });
    }

    public function down(): void
    {
        foreach (['owner_transactions', 'fund_transfers', 'expenses', 'payment_allocations', 'payments', 'receipts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
