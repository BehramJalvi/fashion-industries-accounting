<?php

namespace Database\Factories;

use App\Models\RecurringTransactionExecution;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringTransactionExecution>
 */
class RecurringTransactionExecutionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $scheduledFor = fake()->dateTimeBetween('-1 month', 'now')->format('Y-m-d');

        return [
            'template_id' => RecurringTransactionTemplate::factory(),
            'scheduled_for' => $scheduledFor,
            'transaction_type' => 'expense',
            'document_type' => 'expense',
            'document_id' => 1,
            'status' => 'draft',
            'created_by' => null,
            'generated_at' => now(),
        ];
    }
}
