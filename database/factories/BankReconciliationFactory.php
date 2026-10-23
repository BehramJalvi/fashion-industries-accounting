<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\BankReconciliation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankReconciliation>
 */
class BankReconciliationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $periodStart = fake()->dateTimeBetween('-2 months', '-1 month')->format('Y-m-d');
        $periodEnd = fake()->dateTimeBetween($periodStart, 'now')->format('Y-m-d');

        return [
            'account_id' => Account::query()->where('sub_type', 'bank')->value('id') ?? 1,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'opening_balance' => '0.00',
            'closing_balance' => '0.00',
            'source' => 'manual',
            'status' => 'draft',
            'created_by' => null,
            'reconciled_by' => null,
            'reconciled_at' => null,
        ];
    }
}
