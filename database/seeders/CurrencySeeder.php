<?php

namespace Database\Seeders;

use App\Models\Currency;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        Currency::firstOrCreate(['code' => 'PKR'], ['name' => 'Pakistani Rupee', 'symbol' => 'Rs', 'is_base' => true]);
    }
}
