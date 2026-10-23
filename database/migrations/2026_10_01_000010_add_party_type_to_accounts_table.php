<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $t) {
            // Control accounts (receivable, payable, employee, owner) must carry a matching party on every journal line.
            $t->string('party_type', 20)->nullable()->after('is_control');   // customer | supplier | employee | owner
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $t) {
            $t->dropColumn('party_type');
        });
    }
};
