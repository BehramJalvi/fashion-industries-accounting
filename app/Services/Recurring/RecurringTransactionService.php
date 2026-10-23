<?php

namespace App\Services\Recurring;

use App\Models\Expense;
use App\Models\FundTransfer;
use App\Models\RecurringTransactionExecution;
use App\Models\RecurringTransactionTemplate;
use App\Services\Payments\ExpenseService;
use App\Services\Payments\FundTransferService;
use App\Support\AuditLogger;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class RecurringTransactionService
{
    private const MAX_OCCURRENCES_PER_TEMPLATE = 100;

    public function __construct(
        private ExpenseService $expenses,
        private FundTransferService $transfers,
    ) {}

    /** @param array<string, mixed> $data */
    public function createTemplate(array $data): RecurringTransactionTemplate
    {
        $payload = $data['transaction_type'] === 'expense'
            ? Arr::only($data, ['expense_account_id', 'amount', 'tax_amount', 'description', 'paid_from_account_id', 'notes'])
            : Arr::only($data, ['from_account_id', 'to_account_id', 'amount', 'reference', 'notes']);

        if ($data['transaction_type'] === 'expense' && ! empty($data['owed_to'])) {
            [$payload['payee_type'], $payload['payee_id']] = explode(':', $data['owed_to']);
        }

        return DB::transaction(function () use ($data, $payload) {
            $template = RecurringTransactionTemplate::create([
                'name' => $data['name'],
                'transaction_type' => $data['transaction_type'],
                'payload' => $payload,
                'frequency' => $data['frequency'],
                'starts_on' => $data['starts_on'],
                'next_run_on' => $data['starts_on'],
                'ends_on' => $data['ends_on'] ?? null,
                'is_active' => true,
                'created_by' => Auth::id(),
            ]);

            AuditLogger::log('recurring_transaction.template_created', $template, null, [
                'transaction_type' => $template->transaction_type,
                'frequency' => $template->frequency,
                'starts_on' => $template->starts_on->toDateString(),
                'ends_on' => $template->ends_on?->toDateString(),
            ]);

            return $template;
        });
    }

    public function generateDue(?CarbonImmutable $asOf = null): int
    {
        $asOf ??= CarbonImmutable::today();
        $templateIds = RecurringTransactionTemplate::query()
            ->where('is_active', true)
            ->whereDate('next_run_on', '<=', $asOf->toDateString())
            ->orderBy('next_run_on')
            ->orderBy('id')
            ->pluck('id');

        $generated = 0;
        foreach ($templateIds as $templateId) {
            $generated += $this->generateForTemplate((int) $templateId, $asOf);
        }

        return $generated;
    }

    public function toggle(RecurringTransactionTemplate $template): bool
    {
        return DB::transaction(function () use ($template) {
            $record = RecurringTransactionTemplate::query()->lockForUpdate()->findOrFail($template->id);
            $previous = $record->is_active;

            if (! $previous && $record->ends_on && $record->next_run_on->gt($record->ends_on)) {
                throw new DomainException('This recurring schedule has ended and cannot be resumed.');
            }

            $record->forceFill(['is_active' => ! $previous])->save();
            AuditLogger::log('recurring_transaction.template_toggled', $record, ['is_active' => $previous], ['is_active' => $record->is_active]);

            return $record->is_active;
        });
    }

    private function generateForTemplate(int $templateId, CarbonImmutable $asOf): int
    {
        return DB::transaction(function () use ($templateId, $asOf) {
            $template = RecurringTransactionTemplate::query()->lockForUpdate()->find($templateId);
            if (! $template || ! $template->is_active) {
                return 0;
            }

            $generated = 0;
            $processed = 0;
            while ($template->is_active && $template->next_run_on->lte($asOf) && $processed < self::MAX_OCCURRENCES_PER_TEMPLATE) {
                $scheduledFor = CarbonImmutable::parse($template->next_run_on)->startOfDay();
                if ($template->ends_on && $scheduledFor->gt($template->ends_on)) {
                    $template->forceFill(['is_active' => false])->save();
                    break;
                }

                $executionExists = RecurringTransactionExecution::query()
                    ->where('template_id', $template->id)
                    ->whereDate('scheduled_for', $scheduledFor->toDateString())
                    ->exists();

                if (! $executionExists) {
                    $document = $this->createDraft($template, $scheduledFor);
                    $execution = RecurringTransactionExecution::create([
                        'template_id' => $template->id,
                        'scheduled_for' => $scheduledFor->toDateString(),
                        'transaction_type' => $template->transaction_type,
                        'document_type' => $document->getMorphClass(),
                        'document_id' => $document->getKey(),
                        'status' => 'draft',
                        'created_by' => Auth::id(),
                        'generated_at' => now(),
                    ]);

                    AuditLogger::log('recurring_transaction.draft_generated', $execution, null, [
                        'template_id' => $template->id,
                        'scheduled_for' => $scheduledFor->toDateString(),
                        'document_type' => $execution->document_type,
                        'document_id' => $execution->document_id,
                    ]);
                    $generated++;
                }

                $template->next_run_on = $this->nextOccurrence($template, $scheduledFor)->toDateString();
                if ($template->ends_on && $template->next_run_on->gt($template->ends_on)) {
                    $template->is_active = false;
                }
                $template->save();
                $processed++;
            }

            return $generated;
        });
    }

    private function createDraft(RecurringTransactionTemplate $template, CarbonImmutable $scheduledFor): Expense|FundTransfer
    {
        $payload = $template->payload;

        return match ($template->transaction_type) {
            'expense' => $this->expenses->createDraft($payload + ['expense_date' => $scheduledFor->toDateString()]),
            'fund_transfer' => $this->transfers->createDraft($payload + ['transfer_date' => $scheduledFor->toDateString()]),
            default => throw new DomainException("Unsupported recurring transaction type [{$template->transaction_type}]."),
        };
    }

    private function nextOccurrence(RecurringTransactionTemplate $template, CarbonImmutable $scheduledFor): CarbonImmutable
    {
        $anchor = CarbonImmutable::parse($template->starts_on);

        return match ($template->frequency) {
            'weekly' => $scheduledFor->addWeek(),
            'monthly' => $this->anchoredMonth($scheduledFor, $anchor->day),
            'yearly' => $this->anchoredYear($scheduledFor, $anchor->month, $anchor->day),
            default => throw new DomainException("Unsupported recurring frequency [{$template->frequency}]."),
        };
    }

    private function anchoredMonth(CarbonImmutable $scheduledFor, int $day): CarbonImmutable
    {
        $month = $scheduledFor->startOfMonth()->addMonthNoOverflow();

        return $month->setDay(min($day, $month->daysInMonth));
    }

    private function anchoredYear(CarbonImmutable $scheduledFor, int $monthNumber, int $day): CarbonImmutable
    {
        $year = CarbonImmutable::create($scheduledFor->year + 1, $monthNumber, 1);

        return $year->setDay(min($day, $year->daysInMonth));
    }
}
