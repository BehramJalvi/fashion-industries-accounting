<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreExpenseRequest;
use App\Models\Account;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\Supplier;
use App\Services\Payments\ExpenseService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ExpenseController extends Controller
{
    public function __construct(private ExpenseService $service) {}

    public function index(Request $request): View
    {
        Gate::authorize('expenses.view');
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date']);

        return view('expenses.index', [
            'expenses' => Expense::with(['expenseAccount', 'paidFromAccount'])
                ->when($request->q, fn ($q, $t) => $q->where(fn ($w) => $w->where('description', 'like', "%$t%")->orWhere('expense_no', 'like', "%$t%")))
                ->when($request->expense_account_id, fn ($q, $v) => $q->where('expense_account_id', $v))
                ->when($request->status, fn ($q, $v) => $q->where('status', $v))
                ->when($request->from, fn ($q, $v) => $q->whereDate('expense_date', '>=', $v))
                ->when($request->to, fn ($q, $v) => $q->whereDate('expense_date', '<=', $v))
                ->orderByDesc('expense_date')->orderByDesc('id')->paginate(20)->withQueryString(),
            'accounts' => $this->expenseAccounts(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('expenses.create');

        return view('expenses.create', $this->formData(new Expense(['expense_date' => now()])));
    }

    public function edit(Expense $expense): View|RedirectResponse
    {
        Gate::authorize('expenses.update');

        if (! $expense->isDraft()) {
            return redirect()->route('expenses.show', $expense)->with('error', 'Only a draft expense can be edited. Cancel a posted expense instead.');
        }

        return view('expenses.create', $this->formData($expense));
    }

    public function store(StoreExpenseRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $postNow = ($data['action'] ?? 'draft') === 'post';
        if ($postNow) {
            Gate::authorize('expenses.post');   // before anything is saved
        }

        if (! empty($data['owed_to'])) {
            [$data['payee_type'], $data['payee_id']] = explode(':', $data['owed_to']);
        }

        try {
            $expense = $this->service->createDraft($data);
        } catch (DomainException $e) {
            return back()->withInput()->withErrors(['expense' => $e->getMessage()]);
        }

        if ($postNow) {
            try {
                $this->service->post($expense);
            } catch (DomainException $e) {
                return redirect()->route('expenses.show', $expense)->with('error', "Saved as a draft but not posted: {$e->getMessage()}");
            }
        }

        return redirect()->route('expenses.show', $expense)->with('success', "{$expense->expense_no} ".($postNow ? 'posted.' : 'saved as a draft.'));
    }

    public function update(StoreExpenseRequest $request, Expense $expense): RedirectResponse
    {
        $data = $request->validated();
        $postNow = ($data['action'] ?? 'draft') === 'post';
        if ($postNow) {
            Gate::authorize('expenses.post');
        }
        if (! empty($data['owed_to'])) {
            [$data['payee_type'], $data['payee_id']] = explode(':', $data['owed_to']);
        }

        try {
            $expense = $this->service->updateDraft($expense, $data);
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['expense' => $exception->getMessage()]);
        }

        if ($postNow) {
            try {
                $this->service->post($expense);
            } catch (DomainException $exception) {
                return redirect()->route('expenses.show', $expense)->with('error', "Saved as a draft but not posted: {$exception->getMessage()}");
            }
        }

        return redirect()->route('expenses.show', $expense)->with('success', "{$expense->expense_no} ".($postNow ? 'posted.' : 'updated.'));
    }

    public function show(Expense $expense): View
    {
        Gate::authorize('expenses.view');

        return view('expenses.show', ['expense' => $expense->load(['expenseAccount', 'paidFromAccount', 'journalEntry', 'reversalEntry', 'creator', 'canceller']), 'payee' => $expense->payee()]);
    }

    public function post(Expense $expense): RedirectResponse
    {
        Gate::authorize('expenses.post');

        try {
            $this->service->post($expense);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Expense posted.');
    }

    public function cancel(Request $request, Expense $expense): RedirectResponse
    {
        Gate::authorize('expenses.cancel');
        $request->validate(['reason' => ['required', 'string', 'max:200']]);

        try {
            $this->service->cancel($expense, $request->string('reason')->toString());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Expense cancelled and reversed.');
    }

    public function destroy(Expense $expense): RedirectResponse
    {
        Gate::authorize('expenses.update');

        if (! $expense->isDraft()) {
            return back()->with('error', 'Only a draft can be deleted. Cancel a posted expense instead.');
        }
        $expense->delete();

        return redirect()->route('expenses.index')->with('success', 'Draft deleted.');
    }

    private function expenseAccounts()
    {
        return Account::active()->whereIn('type', ['expense', 'cogs'])->whereNull('party_type')->orderBy('code')->get(['id', 'code', 'name']);
    }

    private function formData(Expense $expense): array
    {
        return [
            'expense' => $expense,
            'accounts' => $this->expenseAccounts(),
            'cashAccounts' => Account::active()->whereIn('sub_type', ['cash', 'bank'])->orderBy('code')->get(['id', 'code', 'name']),
            'suppliers' => Supplier::query()->where(fn ($query) => $query->where('is_active', true)
                ->when($expense->payee_type === 'supplier', fn ($query) => $query->orWhereKey($expense->payee_id)))
                ->orderBy('name')->get(['id', 'name']),
            'employees' => Employee::query()->where(fn ($query) => $query->where('is_active', true)
                ->when($expense->payee_type === 'employee', fn ($query) => $query->orWhereKey($expense->payee_id)))
                ->orderBy('name')->get(['id', 'name']),
        ];
    }
}
