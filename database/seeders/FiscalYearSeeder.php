<?php

namespace Database\Seeders;

use App\Models\FiscalYear;
use Illuminate\Database\Seeder;

class FiscalYearSeeder extends Seeder
{
    /** Pakistan fiscal year: 1 July - 30 June (matches the workbook's 1-Jul-2026 start). */
    public function run(): void
    {
        FiscalYear::firstOrCreate(['name' => '2026-27'], ['start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'is_closed' => false]);
    }
}
