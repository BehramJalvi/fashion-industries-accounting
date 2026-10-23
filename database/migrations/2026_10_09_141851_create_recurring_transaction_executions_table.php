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
        $tableName = 'recurring_transaction_executions';

        if (! Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table) {
                $table->id();
                $table->foreignId('template_id')->constrained('recurring_transaction_templates')->restrictOnDelete();
                $table->date('scheduled_for');
                $table->string('transaction_type', 30);
                $table->string('document_type', 40);
                $table->unsignedBigInteger('document_id');
                $table->enum('status', ['draft', 'skipped'])->default('draft');
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('generated_at');
                $table->timestamps();
            });
        }

        if (! Schema::hasIndex($tableName, ['template_id', 'scheduled_for'], 'unique')) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->unique(['template_id', 'scheduled_for'], 'rte_template_schedule_unique');
            });
        }

        if (! Schema::hasIndex($tableName, ['document_type', 'document_id'])) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->index(['document_type', 'document_id'], 'rte_document_lookup_index');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recurring_transaction_executions');
    }
};
