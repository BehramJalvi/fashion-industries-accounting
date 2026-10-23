<?php

namespace App\Console\Commands;

use App\Services\Recurring\RecurringTransactionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('recurring:generate-drafts')]
#[Description('Generate due recurring expense and transfer drafts for review')]
class GenerateRecurringTransactionDrafts extends Command
{
    public function handle(RecurringTransactionService $service): int
    {
        $generated = $service->generateDue();
        $this->info("Generated {$generated} draft transaction(s) for review.");

        return self::SUCCESS;
    }
}
