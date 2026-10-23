<?php

namespace App\Support;

use Illuminate\Database\Schema\Blueprint;

/**
 * Columns shared by every posted-document table (sales, purchases, receipts, ...).
 * Call as the LAST statement inside Schema::create so the columns sit at the end of the table.
 */
class DocumentColumns
{
    public static function add(Blueprint $t): void
    {
        $t->enum('status', ['draft', 'posted', 'cancelled'])->default('draft')->index();
        $t->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
        $t->foreignId('reversal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
        $t->timestamp('posted_at')->nullable();
        $t->timestamp('cancelled_at')->nullable();
        $t->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
        $t->string('cancel_reason')->nullable();
        $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
        $t->timestamps();
        $t->softDeletes();
    }
}
