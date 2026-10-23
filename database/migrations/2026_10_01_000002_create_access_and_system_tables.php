<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name', 50)->unique();      // admin, accountant
            $t->string('label', 100);
            $t->timestamps();
        });

        Schema::create('permissions', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100)->unique();     // e.g. sales.post
            $t->string('group', 50)->index();      // e.g. sales
            $t->timestamps();
        });

        Schema::create('role_user', function (Blueprint $t) {
            $t->foreignId('role_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->primary(['role_id', 'user_id']);
        });

        Schema::create('permission_role', function (Blueprint $t) {
            $t->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $t->foreignId('role_id')->constrained()->cascadeOnDelete();
            $t->primary(['permission_id', 'role_id']);
        });

        Schema::create('settings', function (Blueprint $t) {
            $t->id();
            $t->string('key', 100)->unique();
            $t->json('value')->nullable();
            $t->string('group', 50)->default('general')->index();
            $t->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('event', 50);                 // created, updated, posted, cancelled, login ...
            $t->string('auditable_type')->nullable();
            $t->unsignedBigInteger('auditable_id')->nullable();
            $t->json('old_values')->nullable();
            $t->json('new_values')->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->string('user_agent')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['auditable_type', 'auditable_id']);
            $t->index(['user_id', 'created_at']);
        });

        Schema::create('currencies', function (Blueprint $t) {
            $t->id();
            $t->string('code', 3)->unique();         // PKR
            $t->string('name', 50);
            $t->string('symbol', 10)->nullable();
            $t->boolean('is_base')->default(false);
            $t->timestamps();
        });

        Schema::create('fiscal_years', function (Blueprint $t) {
            $t->id();
            $t->string('name', 20)->unique();        // 2026-27
            $t->date('start_date');
            $t->date('end_date');
            $t->boolean('is_closed')->default(false);
            $t->timestamps();
        });

        Schema::create('number_sequences', function (Blueprint $t) {
            $t->id();
            $t->string('doc_type', 30)->unique();    // sale, purchase, receipt, ...
            $t->string('prefix', 20)->default('');
            $t->unsignedBigInteger('next_number')->default(1);
            $t->unsignedTinyInteger('padding')->default(3);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['number_sequences', 'fiscal_years', 'currencies', 'audit_logs', 'settings',
                  'permission_role', 'role_user', 'permissions', 'roles'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
