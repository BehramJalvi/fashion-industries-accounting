<?php

namespace Database\Factories;

use App\Models\BankReconciliation;
use App\Models\BankReconciliationLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankReconciliationLine>
 */
class BankReconciliationLineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $statementDate = fake()->dateTimeBetween('-1 month', 'now')->format('Y-m-d');

        return [
            'reconciliation_id' => BankReconciliation::factory(),
            'statement_date' => $statementDate,
            'description' => fake()->sentence(4),
            'reference' => fake()->optional()->bothify('REF-####'),
            'signed_amount' => fake()->randomFloat(2, -10000, 10000) ?: '1.00',
            'matched_journal_entry_line_id' => null,
            'matched_by' => null,
            'matched_at' => null,
            'created_by' => null,
        ];
    }
}
