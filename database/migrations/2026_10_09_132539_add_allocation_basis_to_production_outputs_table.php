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
        Schema::table('production_outputs', function (Blueprint $table) {
            $table->decimal('allocation_basis', 15, 2)->nullable()->after('qty');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('production_outputs', function (Blueprint $table) {
            $table->dropColumn('allocation_basis');
        });
    }
};
