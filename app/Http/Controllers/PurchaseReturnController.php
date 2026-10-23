<?php

namespace App\Http\Controllers;

use App\Http\Requests\PurchaseReturnRequest;
use App\Models\Account;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Services\Purchasing\PurchaseReturnService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class PurchaseReturnController extends Controller
{
    public function __construct(private PurchaseReturnService $service)
    {
    }

    public function index(Request $request): View
    {
        Gate::authorize('purchase_returns.view');
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date']);

        return view('purchase-returns.index', [
            'returns' => PurchaseReturn::with(['purchase', 'supplier'])
                ->when($request->supplier_id, fn ($q, $v) => $q->where('supplier_id', $v))
                ->when($request->status, fn ($q, $v) => $q->where('status', $v))
                ->when($request->from, fn ($q, $v) => $q->whereDate('return_date', '>=', $v))
                ->when($request->to, fn ($q, $v) => $q->whereDate('return_date', '<=', $v))
                ->orderByDesc('return_date')->orderByDesc('id')->paginate(20)->withQueryString(),
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** Step 1: pick a posted purchase. Step 2 (?purchase_id=): enter quantities to return. */
    public function create(Request $request): View
    {
        Gate::authorize('purchase_returns.create');

        if (! $request->purchase_id) {
            return view('purchase-returns.choose', ['purchases' => Purchase::with('supplier')->where('status', 'posted')->orderByDesc('purchase_date')->orderByDesc('id')->limit(200)->get()]);
        }

        $purchase = Purchase::with(['items.product.unit', 'supplier', 'fundedByEmployee'])->where('status', 'posted')->findOrFail($request->purchase_id);

        return view('purchase-returns.create', [
            'purchase' => $purchase, 'available' => $this->service->returnable($purchase),
            'accounts' => Account::active()->whereIn('sub_type', ['cash', 'bank'])->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(PurchaseReturnRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $postNow = ($data['action'] ?? 'draft') === 'post';
        if ($postNow) {
            Gate::authorize('purchase_returns.post');
        }

        $purchase = Purchase::with('items')->findOrFail($data['purchase_id']);

        try {
            $return = $this->service->createDraft($purchase, $data, $data['qty']);
        } catch (DomainException $e) {
            return back()->withInput()->withErrors(['return' => $e->getMessage()]);
        }

        if ($postNow) {
            try {
                $this->service->post($return);
            } catch (DomainException $e) {
                return redirect()->route('purchase-returns.show', $return)->with('error', "Saved as a draft but not posted: {$e->getMessage()}");
            }
        }

        return redirect()->route('purchase-returns.show', $return)->with('success', "{$return->return_no} " . ($postNow ? 'posted.' : 'saved as a draft.'));
    }

    public function show(PurchaseReturn $purchaseReturn): View
    {
        Gate::authorize('purchase_returns.view');

        return view('purchase-returns.show', ['return' => $purchaseReturn->load(['items.product.unit', 'purchase.supplier', 'refundAccount', 'journalEntry', 'reversalEntry', 'creator', 'canceller'])]);
    }

    public function post(PurchaseReturn $purchaseReturn): RedirectResponse
    {
        Gate::authorize('purchase_returns.post');

        try {
            $this->service->post($purchaseReturn);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Return posted.');
    }

    public function cancel(Request $request, PurchaseReturn $purchaseReturn): RedirectResponse
    {
        Gate::authorize('purchase_returns.cancel');
        $request->validate(['reason' => ['required', 'string', 'max:200']]);

        try {
            $this->service->cancel($purchaseReturn, $request->string('reason')->toString());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Return cancelled. Stock and accounts have been reversed.');
    }

    public function destroy(PurchaseReturn $purchaseReturn): RedirectResponse
    {
        Gate::authorize('purchase_returns.update');

        if (! $purchaseReturn->isDraft()) {
            return back()->with('error', 'Only a draft can be deleted. Cancel a posted return instead.');
        }

        DB::transaction(function () use ($purchaseReturn) {
            $purchaseReturn->items()->delete();
            $purchaseReturn->delete();
        });

        return redirect()->route('purchase-returns.index')->with('success', 'Draft deleted.');
    }
}
