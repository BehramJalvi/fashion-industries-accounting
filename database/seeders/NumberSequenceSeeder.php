<?php

namespace Database\Seeders;

use App\Models\NumberSequence;
use Illuminate\Database\Seeder;

class NumberSequenceSeeder extends Seeder
{
    public function run(): void
    {
        $sequences = [
            // doc_type            prefix  next  padding
            ['journal_entry',      'JE-',  1,    6],
            ['sale',               '',     258,  1],   // continues after invoice no. 257 on the sample invoice; change in Settings
            ['purchase',           'PUR-', 1,    4],
            ['purchase_return',    'PRT-', 1,    4],
            ['sales_return',       'SRT-', 1,    4],
            ['receipt',            'RCT-', 1,    4],
            ['payment',            'PAY-', 1,    4],
            ['expense',            'EXP-', 1,    4],
            ['fund_transfer',      'TRF-', 1,    4],
            ['owner_transaction',  'OWN-', 1,    4],
            ['stock_adjustment',   'ADJ-', 1,    4],
            ['production_order',   'PRD-', 1,    4],
        ];

        foreach ($sequences as [$type, $prefix, $next, $padding]) {
            NumberSequence::firstOrCreate(['doc_type' => $type], ['prefix' => $prefix, 'next_number' => $next, 'padding' => $padding]);
        }
    }
}
