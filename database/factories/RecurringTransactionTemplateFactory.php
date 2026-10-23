<?php

namespace Database\Factories;

use App\Models\RecurringTransactionTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringTransactionTemplate>
 */
class RecurringTransactionTemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsOn = fake()->dateTimeBetween('-1 month', '+1 month')->format('Y-m-d');

        return [
            'name' => fake()->unique()->words(3, true),
            'transaction_type' => 'fund_transfer',
            'payload' => ['from_account_id' => 1, 'to_account_id' => 2, 'amount' => '25.00'],
            'frequency' => 'monthly',
            'starts_on' => $startsOn,
            'next_run_on' => $startsOn,
            'ends_on' => null,
            'is_active' => true,
            'created_by' => null,
        ];
    }
}
