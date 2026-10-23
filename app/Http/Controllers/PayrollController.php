<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreatePayrollRunRequest;
use App\Http\Requests\SettlePayrollItemRequest;
use App\Http\Requests\StoreSalaryPeriodRequest;
use App\Models\Account;
use App\Models\Employee;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\SalaryPeriod;
use App\Services\Payroll\PayrollService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PayrollController extends Controller
{
    public function __construct(private PayrollService $service) {}

    public function index(): View
    {
        Gate::authorize('payroll.view');

        return view('payroll.index', [
            'periods' => SalaryPeriod::with('run')
                ->orderByDesc('start_date')
                ->orderByDesc('id')
                ->paginate(20),
        ]);
    }

    public function createPeriod(): View
    {
        Gate::authorize('payroll.create');

        return view('payroll.period-create');
    }

    public function storePeriod(StoreSalaryPeriodRequest $request): RedirectResponse
    {
        try {
            $period = $this->service->createPeriod($request->validated());
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['salary_period' => $exception->getMessage()]);
        }

        return redirect()->route('payroll.periods.show', $period)->with('success', "Salary period {$period->name} created.");
    }

    public function showPeriod(SalaryPeriod $salaryPeriod): View
    {
        Gate::authorize('payroll.view');
        $salaryPeriod->load(['run.journalEntry', 'run.items.employee', 'run.items.components', 'run.items.settlements.payment']);
        $items = $salaryPeriod->run?->items ?? collect();
        foreach ($items as $item) {
            $item->setAttribute('outstanding_cents', $item->balanceCents());
        }

        return view('payroll.period-show', [
            'period' => $salaryPeriod,
            'employees' => $salaryPeriod->run
                ? collect()
                : Employee::query()->where('is_active', true)->orderBy('name')->get(),
            'items' => $items,
            'settlementKeys' => $items->mapWithKeys(fn (PayrollItem $item): array => [$item->id => (string) Str::uuid()]),
            'cashAccounts' => Account::active()->whereIn('sub_type', ['cash', 'bank'])->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function storeRun(CreatePayrollRunRequest $request, SalaryPeriod $salaryPeriod): RedirectResponse
    {
        try {
            $run = $this->service->createDraftRun($salaryPeriod, $request->validated()['employees']);
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['payroll' => $exception->getMessage()]);
        }

        return redirect()->route('payroll.periods.show', $salaryPeriod)->with('success', "{$run->run_no} saved as a draft.");
    }

    public function post(PayrollRun $payrollRun): RedirectResponse
    {
        Gate::authorize('payroll.post');

        try {
            $this->service->post($payrollRun);
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Payroll posted and salary payables recorded.');
    }

    public function cancel(Request $request, PayrollRun $payrollRun): RedirectResponse
    {
        Gate::authorize('payroll.cancel');
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        try {
            $this->service->cancel($payrollRun, $data['reason']);
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Payroll cancelled and its journal entry reversed.');
    }

    public function payslip(PayrollItem $payrollItem): View
    {
        Gate::authorize('payroll.view');
        $item = $payrollItem->load(['run.period', 'employee', 'components', 'settlements.payment']);
        $item->setAttribute('outstanding_cents', $item->balanceCents());

        return view('payroll.payslip', ['item' => $item]);
    }

    public function settle(SettlePayrollItemRequest $request, PayrollItem $payrollItem): RedirectResponse
    {
        try {
            $payment = $this->service->settle($payrollItem, $request->validated());
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['settlement' => $exception->getMessage()]);
        }

        return back()->with('success', "Salary settlement {$payment->payment_no} posted.");
    }
}
