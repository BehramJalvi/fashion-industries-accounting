<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreReceiptRequest;
use App\Models\Account;
use App\Models\Customer;
use App\Models\PaymentAllocation;
use App\Models\Receipt;
use App\Models\Sale;
use App\Services\Payments\SettlementService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ReceiptController extends Controller
{
    public function __construct(private SettlementService $service)
    {
    }

    public function index(Request $request): View
    {
        Gate::authorize('receipts.view');
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date']);

        $receipts = Receipt::with(['customer', 'account'])
            ->when($request->customer_id, fn ($q, $v) => $q->where('customer_id', $v))
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->method, fn ($q, $v) => $q->where('method', $v))
            ->when($request->from, fn ($q, $v) => $q->whereDate('receipt_date', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->whereDate('receipt_date', '<=', $v))
            ->orderByDesc('receipt_date')->orderByDesc('id')->paginate(20)->withQueryString();

        $applied = PaymentAllocation::where('document_type', 'receipt')->where('is_active', true)->whereIn('document_id', $receipts->pluck('id'))
            ->selectRaw('document_id, SUM(amount) as a')->groupBy('document_id')->pluck('a', 'document_id');

        return view('receipts.index', ['receipts' => $receipts, 'applied' => $applied, 'customers' => Customer::orderBy('name')->get(['id', 'name'])]);
    }

    /** Step 1: pick the customer. Step 2 (?customer_id=): amount, account and how it is applied to open invoices. */
    public function create(Request $request): View
    {
        Gate::authorize('receipts.create');

        if (! $request->customer_id) {
            return view('receipts.choose', ['customers' => Customer::where('is_active', true)->orderBy('name')->get(['id', 'name'])]);
        }

        $customer = Customer::findOrFail($request->customer_id);

        return view('receipts.create', [
            'customer' => $customer, 'open' => $this->service->openFor('receipt', $customer->id),
            'accounts' => Account::active()->whereIn('sub_type', ['cash', 'bank'])->orderBy('code')->get(['id', 'code', 'name']),
            'owes' => $customer->ledgerBalance(),
        ]);
    }

    public function store(StoreReceiptRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $postNow = ($data['action'] ?? 'draft') === 'post';
        if ($postNow) {
            Gate::authorize('receipts.post');   // before anything is saved
        }

        try {
            $receipt = $this->service->createDraft('receipt', $data, $data['alloc'] ?? [], $request->boolean('auto_allocate'));
        } catch (DomainException $e) {
            return back()->withInput()->withErrors(['receipt' => $e->getMessage()]);
        }

        if ($postNow) {
            try {
                $this->service->post($receipt);
            } catch (DomainException $e) {
                return redirect()->route('receipts.show', $receipt)->with('error', "Saved as a draft but not posted: {$e->getMessage()}");
            }
        }

        return redirect()->route('receipts.show', $receipt)->with('success', "{$receipt->receipt_no} " . ($postNow ? 'posted.' : 'saved as a draft.'));
    }

    public function show(Receipt $receipt): View
    {
        Gate::authorize('receipts.view');
        $receipt->load(['customer', 'account', 'journalEntry', 'reversalEntry', 'creator', 'canceller']);

        $allocations = PaymentAllocation::where('document_type', 'receipt')->where('document_id', $receipt->id)->orderBy('id')->get();
        $invoices = Sale::whereIn('id', $allocations->pluck('target_id'))->get()->keyBy('id');

        return view('receipts.show', [
            'receipt' => $receipt, 'allocations' => $allocations, 'invoices' => $invoices, 'unallocated' => $receipt->isPosted() ? $receipt->unallocatedCents() : 0,
            'open' => $receipt->isPosted() && $receipt->unallocatedCents() > 0 ? $this->service->openFor('receipt', $receipt->customer_id) : collect(),
        ]);
    }

    public function post(Receipt $receipt): RedirectResponse
    {
        Gate::authorize('receipts.post');

        try {
            $this->service->post($receipt);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Receipt posted.');
    }

    /** Apply the part of a posted receipt that is still "on account" to open invoices. */
    public function allocate(Request $request, Receipt $receipt): RedirectResponse
    {
        Gate::authorize('receipts.post');
        $data = $request->validate(['alloc' => ['required', 'array'], 'alloc.*' => ['nullable', 'numeric', 'min:0', 'max:9999999999999']]);

        try {
            $this->service->applyLater($receipt, $data['alloc']);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Applied to the selected invoices.');
    }

    public function cancel(Request $request, Receipt $receipt): RedirectResponse
    {
        Gate::authorize('receipts.cancel');
        $request->validate(['reason' => ['required', 'string', 'max:200']]);

        try {
            $this->service->cancel($receipt, $request->string('reason')->toString());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Receipt cancelled. The invoices it paid are unpaid again.');
    }

    public function destroy(Receipt $receipt): RedirectResponse
    {
        Gate::authorize('receipts.update');

        try {
            $this->service->deleteDraft($receipt);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('receipts.index')->with('success', 'Draft deleted.');
    }
}
