<?php

namespace App\Http\Controllers;

use App\Http\Requests\PurchaseRequest;
use App\Models\Account;
use App\Models\Employee;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Services\Purchasing\PurchaseService;
use App\Services\Purchasing\PurchaseSummary;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class PurchaseController extends Controller
{
    public function __construct(private PurchaseService $service) {}

    public function index(Request $request): View
    {
        Gate::authorize('purchases.view');
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date']);

        $purchases = Purchase::with('supplier')
            ->when($request->q, fn ($q, $t) => $q->where(fn ($w) => $w->where('purchase_no', 'like', "%$t%")->orWhere('supplier_ref', 'like', "%$t%")))
            ->when($request->supplier_id, fn ($q, $v) => $q->where('supplier_id', $v))
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->from, fn ($q, $v) => $q->whereDate('purchase_date', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->whereDate('purchase_date', '<=', $v))
            ->orderByDesc('purchase_date')->orderByDesc('id')->paginate(20)->withQueryString();

        return view('purchases.index', [
            'purchases' => $purchases, 'summaries' => PurchaseSummary::for($purchases->getCollection()),
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('purchases.create');

        return view('purchases.form', $this->formData(new Purchase(['purchase_date' => now()])));
    }

    public function store(PurchaseRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $postNow = ($data['action'] ?? 'draft') === 'post';
        if ($postNow) {
            Gate::authorize('purchases.post');   // before anything is saved
        }

        try {
            $purchase = $this->service->createDraft($data, $data['items']);
        } catch (DomainException $e) {
            return back()->withInput()->withErrors(['purchase' => $e->getMessage()]);
        }

        return $this->afterSave($purchase, $postNow);
    }

    public function show(Purchase $purchase): View
    {
        Gate::authorize('purchases.view');
        $purchase->load(['items.product.unit', 'supplier', 'fundedByEmployee', 'paidFromAccount', 'journalEntry', 'reversalEntry', 'creator', 'canceller', 'returns', 'invoicePrint.printedBy']);

        return view('purchases.show', ['purchase' => $purchase, 'summary' => $purchase->summary()]);
    }

    public function edit(Purchase $purchase): View|RedirectResponse
    {
        Gate::authorize('purchases.update');

        if (! $purchase->isDraft()) {
            return redirect()->route('purchases.show', $purchase)->with('error', 'Only a draft can be edited. Cancel a posted purchase and enter it again.');
        }

        return view('purchases.form', $this->formData($purchase->load('items')));
    }

    public function update(PurchaseRequest $request, Purchase $purchase): RedirectResponse
    {
        $data = $request->validated();
        $postNow = ($data['action'] ?? 'draft') === 'post';
        if ($postNow) {
            Gate::authorize('purchases.post');
        }

        try {
            $purchase = $this->service->updateDraft($purchase, $data, $data['items']);
        } catch (DomainException $e) {
            return back()->withInput()->withErrors(['purchase' => $e->getMessage()]);
        }

        return $this->afterSave($purchase, $postNow);
    }

    public function post(Purchase $purchase): RedirectResponse
    {
        Gate::authorize('purchases.post');

        try {
            $this->service->post($purchase);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Purchase posted.');
    }

    public function cancel(Request $request, Purchase $purchase): RedirectResponse
    {
        Gate::authorize('purchases.cancel');
        $request->validate(['reason' => ['required', 'string', 'max:200']]);

        try {
            $this->service->cancel($purchase, $request->string('reason')->toString());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Purchase cancelled. Stock and accounts have been reversed.');
    }

    /** Drafts only. A posted purchase is cancelled, never deleted. */
    public function destroy(Purchase $purchase): RedirectResponse
    {
        Gate::authorize('purchases.update');

        if (! $purchase->isDraft()) {
            return back()->with('error', 'Only a draft can be deleted. Cancel a posted purchase instead.');
        }

        DB::transaction(function () use ($purchase) {
            $purchase->items()->delete();
            $purchase->delete();
        });

        return redirect()->route('purchases.index')->with('success', 'Draft deleted.');
    }

    private function afterSave(Purchase $purchase, bool $postNow): RedirectResponse
    {
        if ($postNow) {
            try {
                $this->service->post($purchase);
            } catch (DomainException $e) {
                // the draft is safe; tell the user why it was not posted
                return redirect()->route('purchases.show', $purchase)->with('error', "Saved as a draft but not posted: {$e->getMessage()}");
            }
        }

        return redirect()->route('purchases.show', $purchase)->with('success', "{$purchase->purchase_no} ".($postNow ? 'posted.' : 'saved as a draft.'));
    }

    private function formData(Purchase $purchase): array
    {
        return [
            'purchase' => $purchase,
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'employees' => Employee::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'accounts' => Account::active()->whereIn('sub_type', ['cash', 'bank'])->orderBy('code')->get(['id', 'code', 'name']),
            'products' => Product::where('is_active', true)->with('unit')->orderBy('name')->get(),
        ];
    }
}
