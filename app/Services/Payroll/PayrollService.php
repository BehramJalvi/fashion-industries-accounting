<?php

namespace App\Services\Payroll;

use App\Models\Account;
use App\Models\Employee;
use App\Models\JournalEntryLine;
use App\Models\Payment;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\PayrollSettlement;
use App\Models\SalaryPeriod;
use App\Services\Accounting\PostingService;
use App\Services\Payments\SettlementService;
use App\Support\AccountCodes;
use App\Support\Money;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PayrollService
{
    public function __construct(
        private PostingService $posting,
        private SettlementService $settlements,
    ) {}

    /** @param array<string, mixed> $data */
    public function createPeriod(array $data): SalaryPeriod
    {
        return DB::transaction(function () use ($data) {
            $overlaps = SalaryPeriod::query()
                ->whereDate('start_date', '<=', $data['end_date'])
                ->whereDate('end_date', '>=', $data['start_date'])
                ->lockForUpdate()
                ->exists();
            if ($overlaps) {
                throw new DomainException('Salary periods cannot overlap.');
            }

            $period = new SalaryPeriod([
                'name' => $data['name'],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'working_days' => (int) $data['working_days'],
            ]);
            $period->forceFill(['created_by' => Auth::id()])->save();

            return $period;
        });
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $employees
     */
    public function createDraftRun(SalaryPeriod $salaryPeriod, array $employees): PayrollRun
    {
        return DB::transaction(function () use ($salaryPeriod, $employees) {
            $period = SalaryPeriod::query()->lockForUpdate()->findOrFail($salaryPeriod->id);
            if ($period->run()->exists()) {
                throw new DomainException("A payroll run already exists for {$period->name}.");
            }

            $employees = collect($employees)
                ->filter(fn (array $data): bool => (bool) ($data['include'] ?? false))
                ->all();
            if (! $employees) {
                throw new DomainException('Select at least one employee for this payroll run.');
            }

            $ids = array_map('intval', array_keys($employees));
            /** @var Collection<int, Employee> $activeEmployees */
            $activeEmployees = Employee::query()
                ->whereIn('id', $ids)
                ->where('is_active', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            if ($activeEmployees->count() !== count(array_unique($ids))) {
                throw new DomainException('Only active employees can be included in payroll.');
            }

            $run = $period->run()->create([
                'run_no' => 'PR-'.strtoupper($period->name),
            ]);
            $run->forceFill(['status' => 'draft', 'created_by' => Auth::id()])->save();

            foreach ($employees as $employeeId => $data) {
                $employee = $activeEmployees[(int) $employeeId];
                $presentDays = (int) $data['present_days'];
                $paidLeaveDays = (int) ($data['paid_leave_days'] ?? 0);
                $unpaidLeaveDays = (int) ($data['unpaid_leave_days'] ?? 0);
                if ($presentDays + $paidLeaveDays + $unpaidLeaveDays > $period->working_days) {
                    throw new DomainException("Attendance for {$employee->name} exceeds the {$period->working_days} working days in this period.");
                }

                $monthlySalaryCents = Money::toCents($employee->monthly_salary);
                $paidDays = $presentDays + $paidLeaveDays;
                $basePayCents = intdiv(
                    $monthlySalaryCents * $paidDays + intdiv((int) $period->working_days, 2),
                    (int) $period->working_days,
                );
                $allowances = $this->normaliseComponents($data['allowances'] ?? []);
                $deductions = $this->normaliseComponents($data['deductions'] ?? []);
                $allowanceCents = array_sum(array_column($allowances, 'amount_cents'));
                $deductionCents = array_sum(array_column($deductions, 'amount_cents'));
                $advanceCents = Money::toCents($data['advance_adjustment'] ?? 0);
                if ($advanceCents > $this->employeeAdvanceBalance($employee)) {
                    throw new DomainException("The advance adjustment for {$employee->name} exceeds their outstanding employee advance.");
                }

                $netPayCents = $basePayCents + $allowanceCents - $deductionCents - $advanceCents;
                if ($netPayCents < 0) {
                    throw new DomainException("Allowances and deductions for {$employee->name} cannot result in negative net pay.");
                }

                $item = $run->items()->create([
                    'employee_id' => $employee->id,
                    'monthly_salary' => $employee->monthly_salary,
                    'present_days' => $presentDays,
                    'paid_leave_days' => $paidLeaveDays,
                    'unpaid_leave_days' => $unpaidLeaveDays,
                    'base_pay' => Money::fromCents($basePayCents),
                    'allowance_total' => Money::fromCents($allowanceCents),
                    'deduction_total' => Money::fromCents($deductionCents),
                    'advance_adjustment' => Money::fromCents($advanceCents),
                    'net_pay' => Money::fromCents($netPayCents),
                ]);
                foreach ([['allowance', $allowances], ['deduction', $deductions]] as [$type, $components]) {
                    foreach ($components as $component) {
                        $item->components()->create([
                            'component_type' => $type,
                            'description' => $component['description'],
                            'amount' => Money::fromCents($component['amount_cents']),
                        ]);
                    }
                }
            }

            return $run->load('items.employee', 'period');
        });
    }

    public function post(PayrollRun $payrollRun): PayrollRun
    {
        return DB::transaction(function () use ($payrollRun) {
            $run = PayrollRun::query()
                ->with(['period', 'items.employee', 'items.components'])
                ->lockForUpdate()
                ->findOrFail($payrollRun->id);
            if (! $run->isDraft()) {
                throw new DomainException('Only a draft payroll run can be posted.');
            }
            if ($run->items->isEmpty()) {
                throw new DomainException('A payroll run must contain at least one payslip.');
            }

            $grossCents = 0;
            $payableLines = [];
            $advanceLines = [];
            $deductionCents = 0;
            foreach ($run->items as $item) {
                $gross = Money::toCents($item->base_pay) + Money::toCents($item->allowance_total);
                $deduction = Money::toCents($item->deduction_total);
                $advance = Money::toCents($item->advance_adjustment);
                $net = Money::toCents($item->net_pay);
                if ($gross !== $deduction + $advance + $net) {
                    throw new DomainException("The payslip for {$item->employee->name} does not balance.");
                }

                $grossCents += $gross;
                $deductionCents += $deduction;
                if ($net > 0) {
                    $payableLines[] = [
                        'account' => AccountCodes::EMPLOYEE_PAYABLE,
                        'credit' => Money::fromCents($net),
                        'party' => $item->employee,
                        'narration' => "Net salary payable for {$item->employee->name}",
                    ];
                }
                if ($advance > 0) {
                    $advanceLines[] = [
                        'account' => AccountCodes::EMPLOYEE_ADVANCE,
                        'credit' => Money::fromCents($advance),
                        'party' => $item->employee,
                        'narration' => "Employee advance recovered from {$item->employee->name}",
                    ];
                }
            }
            if ($grossCents <= 0) {
                throw new DomainException('A payroll run must include a positive salary or allowance amount.');
            }

            $lines = [[
                'account' => AccountCodes::SALARIES,
                'debit' => Money::fromCents($grossCents),
                'narration' => "Gross payroll expense for {$run->period->name}",
            ], ...$payableLines, ...$advanceLines];
            if ($deductionCents > 0) {
                $lines[] = [
                    'account' => AccountCodes::OTHER_PAYABLES,
                    'credit' => Money::fromCents($deductionCents),
                    'narration' => "Payroll deductions for {$run->period->name}",
                ];
            }

            $entry = $this->posting->post([
                'date' => $run->period->end_date,
                'voucher_type' => 'payroll',
                'reference' => $run->run_no,
                'source' => $run,
                'description' => "Payroll {$run->run_no} for {$run->period->name}",
                'lines' => $lines,
            ]);
            $run->forceFill([
                'status' => 'posted',
                'journal_entry_id' => $entry->id,
                'posted_at' => now(),
                'updated_by' => Auth::id(),
            ])->save();

            return $run;
        });
    }

    public function cancel(PayrollRun $payrollRun, string $reason): PayrollRun
    {
        return DB::transaction(function () use ($payrollRun, $reason) {
            $run = PayrollRun::query()->with('items.settlements.payment')->lockForUpdate()->findOrFail($payrollRun->id);
            if (! $run->isPosted()) {
                throw new DomainException('Only a posted payroll run can be cancelled.');
            }
            if ($run->items->flatMap->settlements->contains(fn (PayrollSettlement $settlement): bool => $settlement->payment?->isPosted())) {
                throw new DomainException('Cancel all posted salary payments before reversing this payroll run.');
            }

            $reversal = $this->posting->reverse($run->journalEntry, $reason);
            $run->forceFill([
                'status' => 'cancelled',
                'reversal_entry_id' => $reversal->id,
                'cancelled_at' => now(),
                'cancelled_by' => Auth::id(),
                'cancel_reason' => $reason,
                'updated_by' => Auth::id(),
            ])->save();

            return $run;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function settle(PayrollItem $payrollItem, array $data): Payment
    {
        return DB::transaction(function () use ($payrollItem, $data) {
            $existing = PayrollSettlement::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                if ((int) $existing->payroll_item_id !== (int) $payrollItem->id) {
                    throw new DomainException('This payment request key has already been used for another payslip.');
                }

                return $existing->payment;
            }

            $item = PayrollItem::query()->with(['run', 'employee'])->lockForUpdate()->findOrFail($payrollItem->id);
            if (! $item->run->isPosted()) {
                throw new DomainException('Salary can only be settled after the payroll run is posted.');
            }
            $amountCents = Money::toCents($data['amount']);
            $balanceCents = $item->balanceCents();
            if ($balanceCents <= 0) {
                throw new DomainException('This payslip has already been paid in full.');
            }
            if ($amountCents > $balanceCents) {
                throw new DomainException('The payment cannot exceed the remaining payslip balance.');
            }

            $payment = $this->settlements->createDraft('payment', [
                'payee_type' => 'employee',
                'payee_id' => $item->employee_id,
                'payment_date' => $data['payment_date'],
                'method' => $data['method'],
                'account_id' => $data['account_id'],
                'amount' => Money::fromCents($amountCents),
                'reference' => $data['reference'] ?? $item->run->run_no,
                'notes' => "Salary settlement for {$item->run->run_no}",
            ]);
            $payment = $this->settlements->post($payment);
            PayrollSettlement::create([
                'payroll_item_id' => $item->id,
                'payment_id' => $payment->id,
                'idempotency_key' => $data['idempotency_key'],
                'amount' => Money::fromCents($amountCents),
                'created_by' => Auth::id(),
            ]);

            return $payment;
        });
    }

    /**
     * @param  array<int, array{description?:string, amount?:string|float|int}>  $components
     * @return array<int, array{description:string, amount_cents:int}>
     */
    private function normaliseComponents(array $components): array
    {
        $normalised = [];
        foreach ($components as $component) {
            $description = trim((string) ($component['description'] ?? ''));
            $amountCents = Money::toCents($component['amount'] ?? 0);
            if ($description === '' && $amountCents === 0) {
                continue;
            }
            if ($description === '' || $amountCents <= 0) {
                throw new DomainException('Each payroll allowance or deduction requires a description and a positive amount.');
            }
            $normalised[] = ['description' => $description, 'amount_cents' => $amountCents];
        }

        return $normalised;
    }

    private function employeeAdvanceBalance(Employee $employee): int
    {
        $accountId = Account::query()->where('code', AccountCodes::EMPLOYEE_ADVANCE)->value('id');
        if (! $accountId) {
            throw new DomainException('The Employee Advances account is missing from the chart of accounts.');
        }

        $lines = JournalEntryLine::query()
            ->where('account_id', $accountId)
            ->where('party_type', $employee->getMorphClass())
            ->where('party_id', $employee->id)
            ->selectRaw('COALESCE(SUM(debit), 0) as debits, COALESCE(SUM(credit), 0) as credits')
            ->first();

        return Money::toCents($lines->debits) - Money::toCents($lines->credits);
    }
}
