<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $t) {
            $t->id();
            $t->string('code', 10)->unique();
            $t->string('name', 150);
            $t->enum('type', ['asset', 'liability', 'equity', 'revenue', 'cogs', 'expense'])->index();
            $t->string('sub_type', 50)->nullable();
            $t->enum('normal_balance', ['debit', 'credit']);
            $t->foreignId('parent_id')->nullable()->constrained('accounts')->nullOnDelete();
            $t->boolean('is_control')->default(false);   // AR / AP / employee control accounts
            $t->boolean('is_system')->default(false);    // cannot be deleted or re-coded
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('journal_entries', function (Blueprint $t) {
            $t->id();
            $t->string('entry_no', 30)->unique();
            $t->date('entry_date')->index();
            $t->foreignId('fiscal_year_id')->nullable()->constrained()->nullOnDelete();
            $t->string('voucher_type', 30)->index();     // sale, purchase, receipt, payment, expense, transfer, owner, adjustment, production, opening, journal, reversal
            $t->string('reference', 60)->nullable()->index();
            $t->text('description')->nullable();
            $t->string('source_type', 50)->nullable();   // morph-map alias of the source document
            $t->unsignedBigInteger('source_id')->nullable();
            $t->enum('status', ['posted', 'cancelled'])->default('posted')->index();
            $t->foreignId('reverses_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $t->decimal('total_amount', 15, 2);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('posted_at')->useCurrent();
            $t->timestamps();
            $t->index(['source_type', 'source_id']);
            // Journal entries are never soft-deleted or deleted: cancel = status change + reversing entry.
        });

        Schema::create('journal_entry_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('journal_entry_id')->constrained()->restrictOnDelete();
            $t->unsignedSmallInteger('line_no');
            $t->foreignId('account_id')->constrained()->restrictOnDelete();
            $t->decimal('debit', 15, 2)->default(0);
            $t->decimal('credit', 15, 2)->default(0);
            $t->string('party_type', 20)->nullable();    // customer | supplier | employee | owner (morph map)
            $t->unsignedBigInteger('party_id')->nullable();
            $t->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $t->string('narration')->nullable();
            $t->timestamps();
            $t->unique(['journal_entry_id', 'line_no']);
            $t->index('account_id');
            $t->index(['party_type', 'party_id']);
        });

        // One side only per line, never negative. Enforced by MySQL 8.0.16+ (skipped on SQLite used by the test suite).
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE journal_entry_lines ADD CONSTRAINT chk_jel_amounts
                CHECK (debit >= 0 AND credit >= 0 AND (debit = 0 OR credit = 0))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entry_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('accounts');
    }
};
