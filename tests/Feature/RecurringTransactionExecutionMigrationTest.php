<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RecurringTransactionExecutionMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_completes_the_indexes_when_resuming_a_partially_created_table(): void
    {
        Schema::drop('recurring_transaction_executions');
        Schema::create('recurring_transaction_executions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('template_id');
            $table->date('scheduled_for');
            $table->string('transaction_type', 30);
            $table->string('document_type', 40);
            $table->unsignedBigInteger('document_id');
            $table->string('status', 20)->default('draft');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('generated_at');
            $table->timestamps();
        });

        $migration = require database_path('migrations/2026_10_09_141851_create_recurring_transaction_executions_table.php');
        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasIndex('recurring_transaction_executions', ['template_id', 'scheduled_for'], 'unique'));
        $this->assertTrue(Schema::hasIndex('recurring_transaction_executions', ['document_type', 'document_id']));
    }
}
