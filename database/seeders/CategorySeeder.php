<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Unit;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /** Starting lists only; the Excel import (Phase 14) maps the workbook's free-text categories onto these. */
    public function run(): void
    {
        foreach (['Yarn / Raw Material', 'Sewing Thread', 'Accessories & Trims', 'Packaging', 'Other'] as $name) {
            Category::firstOrCreate(['name' => $name], ['is_active' => true]);
        }

        foreach ([['Bundle', 'bdl'], ['Cone', 'cone'], ['Kg', 'kg'], ['Piece', 'pc'], ['Dozen', 'doz'], ['Meter', 'm']] as [$name, $short]) {
            Unit::firstOrCreate(['name' => $name], ['short_name' => $short]);
        }
    }
}
