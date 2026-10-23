<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOwnerTransactionRequest;
use App\Models\Account;
use App\Models\Owner;
use App\Models\OwnerTransaction;
use App\Services\Accounting\OwnerTransactionService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class OwnerTransactionController extends Controller
{
    public function __construct(private OwnerTransactionService $service)
    {
    }

    public function index(Request $request): View
    {
        Gate::authorize('owner_transactions.view');
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date']);

        return view('owner-transactions.index', [
            'transactions' => OwnerTransaction::with(['owner', 'account'])
                ->when($request->owner_id, fn ($q, $v) => $q->where('owner_id', $v))
                ->when($request->txn_type, fn ($q, $v) => $q->where('txn_type', $v))
                ->when($request->status, fn ($q, $v) => $q->where('status', $v))
                ->when($request->from, fn ($q, $v) => $q->whereDate('txn_date', '>=', $v))
                ->when($request->to, fn ($q, $v) => $q->whereDate('txn_date', '<=', $v))
                ->orderByDesc('txn_date')->orderByDesc('id')->paginate(20)->withQueryString(),
            'owners' => Owner::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('owner_transactions.create');

        return view('owner-transactions.create', [
            'owners' => Owner::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'accounts' => Account::active()->whereIn('sub_type', ['cash', 'bank'])->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(StoreOwnerTransactionRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $postNow = ($data['action'] ?? 'draft') === 'post';
        if ($postNow) {
            Gate::authorize('owner_transactions.post');   // checked before anything is saved
        }

        try {
            $txn = $this->service->createDraft(collect($data)->except('action')->all());
            if ($postNow) {
                $this->service->post($txn);
            }
        } catch (DomainException $e) {
            return back()->withInput()->withErrors(['amount' => $e->getMessage()]);
        }

        return redirect()->route('owner-transactions.show', $txn)->with('success', "{$txn->txn_no} saved.");
    }

    public function show(OwnerTransaction $ownerTransaction): View
    {
        Gate::authorize('owner_transactions.view');

        return view('owner-transactions.show', ['txn' => $ownerTransaction->load(['owner', 'account', 'journalEntry', 'reversalEntry', 'creator', 'canceller'])]);
    }

    public function post(OwnerTransaction $ownerTransaction): RedirectResponse
    {
        Gate::authorize('owner_transactions.post');

        try {
            $this->service->post($ownerTransaction);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Posted.');
    }

    public function cancel(Request $request, OwnerTransaction $ownerTransaction): RedirectResponse
    {
        Gate::authorize('owner_transactions.cancel');
        $request->validate(['reason' => ['required', 'string', 'max:200']]);

        try {
            $this->service->cancel($ownerTransaction, $request->string('reason')->toString());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Cancelled and reversed.');
    }

    public function destroy(OwnerTransaction $ownerTransaction): RedirectResponse
    {
        Gate::authorize('owner_transactions.update');

        try {
            $ownerTransaction->delete();    // drafts only; posted/cancelled throw
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('owner-transactions.index')->with('success', 'Draft deleted.');
    }
}
