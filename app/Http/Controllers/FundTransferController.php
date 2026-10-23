<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFundTransferRequest;
use App\Models\Account;
use App\Models\FundTransfer;
use App\Services\Payments\FundTransferService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class FundTransferController extends Controller
{
    public function __construct(private FundTransferService $service) {}

    public function index(Request $request): View
    {
        Gate::authorize('fund_transfers.view');
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date']);

        return view('fund-transfers.index', [
            'transfers' => FundTransfer::with(['fromAccount', 'toAccount'])
                ->when($request->status, fn ($q, $v) => $q->where('status', $v))
                ->when($request->from, fn ($q, $v) => $q->whereDate('transfer_date', '>=', $v))
                ->when($request->to, fn ($q, $v) => $q->whereDate('transfer_date', '<=', $v))
                ->orderByDesc('transfer_date')->orderByDesc('id')->paginate(20)->withQueryString(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('fund_transfers.create');

        return view('fund-transfers.create', [
            'transfer' => new FundTransfer(['transfer_date' => now()]),
            'accounts' => $this->cashAccounts(),
        ]);
    }

    public function edit(FundTransfer $fundTransfer): View|RedirectResponse
    {
        Gate::authorize('fund_transfers.update');

        if (! $fundTransfer->isDraft()) {
            return redirect()->route('fund-transfers.show', $fundTransfer)->with('error', 'Only a draft transfer can be edited. Cancel a posted transfer instead.');
        }

        return view('fund-transfers.create', [
            'transfer' => $fundTransfer,
            'accounts' => $this->cashAccounts(),
        ]);
    }

    public function store(StoreFundTransferRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $postNow = ($data['action'] ?? 'draft') === 'post';
        if ($postNow) {
            Gate::authorize('fund_transfers.post');
        }

        try {
            $transfer = $this->service->createDraft($data);
        } catch (DomainException $e) {
            return back()->withInput()->withErrors(['transfer' => $e->getMessage()]);
        }

        if ($postNow) {
            try {
                $this->service->post($transfer);
            } catch (DomainException $e) {
                return redirect()->route('fund-transfers.show', $transfer)->with('error', "Saved as a draft but not posted: {$e->getMessage()}");
            }
        }

        return redirect()->route('fund-transfers.show', $transfer)->with('success', "{$transfer->transfer_no} ".($postNow ? 'posted.' : 'saved as a draft.'));
    }

    public function update(StoreFundTransferRequest $request, FundTransfer $fundTransfer): RedirectResponse
    {
        $data = $request->validated();
        $postNow = ($data['action'] ?? 'draft') === 'post';
        if ($postNow) {
            Gate::authorize('fund_transfers.post');
        }

        try {
            $fundTransfer = $this->service->updateDraft($fundTransfer, $data);
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['transfer' => $exception->getMessage()]);
        }

        if ($postNow) {
            try {
                $this->service->post($fundTransfer);
            } catch (DomainException $exception) {
                return redirect()->route('fund-transfers.show', $fundTransfer)->with('error', "Saved as a draft but not posted: {$exception->getMessage()}");
            }
        }

        return redirect()->route('fund-transfers.show', $fundTransfer)->with('success', "{$fundTransfer->transfer_no} ".($postNow ? 'posted.' : 'updated.'));
    }

    public function show(FundTransfer $fundTransfer): View
    {
        Gate::authorize('fund_transfers.view');

        return view('fund-transfers.show', ['transfer' => $fundTransfer->load(['fromAccount', 'toAccount', 'journalEntry', 'reversalEntry', 'creator', 'canceller'])]);
    }

    public function post(FundTransfer $fundTransfer): RedirectResponse
    {
        Gate::authorize('fund_transfers.post');

        try {
            $this->service->post($fundTransfer);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Transfer posted.');
    }

    public function cancel(Request $request, FundTransfer $fundTransfer): RedirectResponse
    {
        Gate::authorize('fund_transfers.cancel');
        $request->validate(['reason' => ['required', 'string', 'max:200']]);

        try {
            $this->service->cancel($fundTransfer, $request->string('reason')->toString());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Transfer cancelled and reversed.');
    }

    public function destroy(FundTransfer $fundTransfer): RedirectResponse
    {
        Gate::authorize('fund_transfers.update');

        if (! $fundTransfer->isDraft()) {
            return back()->with('error', 'Only a draft can be deleted. Cancel a posted transfer instead.');
        }
        $fundTransfer->delete();

        return redirect()->route('fund-transfers.index')->with('success', 'Draft deleted.');
    }

    private function cashAccounts()
    {
        return Account::active()->whereIn('sub_type', ['cash', 'bank'])->orderBy('code')->get(['id', 'code', 'name']);
    }
}
