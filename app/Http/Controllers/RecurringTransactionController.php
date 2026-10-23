<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRecurringTransactionTemplateRequest;
use App\Models\Account;
use App\Models\Employee;
use App\Models\RecurringTransactionTemplate;
use App\Models\Supplier;
use App\Services\Recurring\RecurringTransactionService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class RecurringTransactionController extends Controller
{
    public function __construct(private RecurringTransactionService $service) {}

    public function index(): View
    {
        Gate::authorize('recurring_transactions.view');

        return view('recurring-transactions.index', [
            'templates' => RecurringTransactionTemplate::query()
                ->with('creator')
                ->orderByDesc('is_active')
                ->orderBy('next_run_on')
                ->orderBy('id')
                ->paginate(20),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('recurring_transactions.create');

        return view('recurring-transactions.create', [
            'expenseAccounts' => Account::active()->whereIn('type', ['expense', 'cogs'])->whereNull('party_type')->orderBy('code')->get(['id', 'code', 'name']),
            'cashAccounts' => Account::active()->whereIn('sub_type', ['cash', 'bank'])->orderBy('code')->get(['id', 'code', 'name']),
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'employees' => Employee::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StoreRecurringTransactionTemplateRequest $request): RedirectResponse
    {
        $template = $this->service->createTemplate($request->validated());

        return redirect()->route('recurring-transactions.show', $template)->with('success', 'Recurring schedule created.');
    }

    public function show(RecurringTransactionTemplate $template): View
    {
        Gate::authorize('recurring_transactions.view');

        return view('recurring-transactions.show', [
            'template' => $template->load('creator'),
            'executions' => $template->executions()->with('document')->orderByDesc('scheduled_for')->orderByDesc('id')->paginate(25),
        ]);
    }

    public function runDue(): RedirectResponse
    {
        Gate::authorize('recurring_transactions.run');

        $generated = $this->service->generateDue();

        return back()->with('success', "Generated {$generated} draft transaction(s) for review. Nothing was posted automatically.");
    }

    public function toggle(RecurringTransactionTemplate $template): RedirectResponse
    {
        Gate::authorize('recurring_transactions.update');

        try {
            $isActive = $this->service->toggle($template);
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', $isActive ? 'Recurring schedule resumed.' : 'Recurring schedule paused.');
    }
}
