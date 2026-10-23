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
        Schema::create('salary_periods', function (Blueprint $table) {
            $table->id();
            $table->string('name', 20)->unique();
            $table->date('start_date')->index();
            $table->date('end_date')->index();
            $table->unsignedSmallInteger('working_days');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_period_id')->unique()->constrained()->restrictOnDelete();
            $table->string('run_no', 30)->unique();
            $table->enum('status', ['draft', 'posted', 'cancelled'])->default('draft')->index();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('reversal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('payroll_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->decimal('monthly_salary', 15, 2);
            $table->unsignedSmallInteger('present_days')->default(0);
            $table->unsignedSmallInteger('paid_leave_days')->default(0);
            $table->unsignedSmallInteger('unpaid_leave_days')->default(0);
            $table->decimal('base_pay', 15, 2);
            $table->decimal('allowance_total', 15, 2)->default(0);
            $table->decimal('deduction_total', 15, 2)->default(0);
            $table->decimal('advance_adjustment', 15, 2)->default(0);
            $table->decimal('net_pay', 15, 2);
            $table->timestamps();
            $table->unique(['payroll_run_id', 'employee_id']);
        });

        Schema::create('payroll_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_item_id')->constrained()->cascadeOnDelete();
            $table->enum('component_type', ['allowance', 'deduction']);
            $table->string('description', 100);
            $table->decimal('amount', 15, 2);
            $table->timestamps();
            $table->index(['payroll_item_id', 'component_type']);
        });

        Schema::create('payroll_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->unique()->constrained()->restrictOnDelete();
            $table->uuid('idempotency_key')->unique();
            $table->decimal('amount', 15, 2);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('payroll_item_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payroll_settlements');
        Schema::dropIfExists('payroll_components');
        Schema::dropIfExists('payroll_items');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('salary_periods');
    }
};
