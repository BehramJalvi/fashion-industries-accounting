<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAccountRequest;
use App\Http\Requests\UpdateAccountRequest;
use App\Models\Account;
use App\Models\JournalEntryLine;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function index(): View
    {
        Gate::authorize('accounts.view');

        // Balances are derived from the journal, never stored.
        $totals = JournalEntryLine::query()
            ->selectRaw('account_id, SUM(debit) as d, SUM(credit) as c')->groupBy('account_id')->get()->keyBy('account_id');

        $accounts = Account::orderBy('code')->get()->each(function (Account $a) use ($totals) {
            $t = $totals->get($a->id);
            $net = $t ? (float) $t->d - (float) $t->c : 0.0;
            $a->setAttribute('balance', $a->isDebitNormal() ? $net : 0 - $net);
        });

        return view('accounts.index', ['groups' => $accounts->groupBy('type')]);
    }

    public function create(): View
    {
        Gate::authorize('accounts.create');

        return view('accounts.form', ['account' => new Account(['is_active' => true, 'type' => 'expense', 'normal_balance' => 'debit'])]);
    }

    public function store(StoreAccountRequest $request): RedirectResponse
    {
        Account::create($request->validated() + ['is_active' => true]);

        return redirect()->route('accounts.index')->with('success', 'Account created.');
    }

    public function edit(Account $account): View
    {
        Gate::authorize('accounts.update');

        return view('accounts.form', ['account' => $account]);
    }

    public function update(UpdateAccountRequest $request, Account $account): RedirectResponse
    {
        $data = $request->validated();
        $active = $request->boolean('is_active', $account->is_active);

        if ($account->is_system && ! $active) {
            return back()->withInput()->withErrors(['is_active' => 'System accounts cannot be deactivated.']);
        }

        $account->update(['name' => $data['name'], 'sub_type' => $data['sub_type'] ?? $account->sub_type, 'is_active' => $active]);

        return redirect()->route('accounts.index')->with('success', 'Account updated.');
    }

    public function destroy(Account $account): RedirectResponse
    {
        Gate::authorize('accounts.delete');

        try {
            $account->delete();
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('accounts.index')->with('success', 'Account deleted.');
    }
}
