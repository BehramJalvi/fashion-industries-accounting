<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaleRequest;
use App\Models\Account;
use App\Models\Customer;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\Sale;
use App\Services\Sales\SaleService;
use App\Services\Sales\SaleSummary;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class SaleController extends Controller
{
    public function __construct(private SaleService $service)
    {
    }

    public function index(Request $request): View
    {
        Gate::authorize('sales.view');
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date']);

        $sales = Sale::with('customer')
            ->when($request->q, fn ($q, $t) => $q->where(fn ($w) => $w->where('invoice_no', 'like', "%$t%")->orWhere('buyer_name', 'like', "%$t%")->orWhere('gate_pass_no', 'like', "%$t%")))
            ->when($request->customer_id, fn ($q, $v) => $q->where('customer_id', $v))
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->from, fn ($q, $v) => $q->whereDate('sale_date', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->whereDate('sale_date', '<=', $v))
            ->orderByDesc('sale_date')->orderByDesc('id')->paginate(20)->withQueryString();

        return view('sales.index', [
            'sales' => $sales, 'summaries' => SaleSummary::for($sales->getCollection()),
            'customers' => Customer::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('sales.create');

        return view('sales.form', $this->formData(new Sale(['sale_date' => now()])));
    }

    public function store(SaleRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $postNow = ($data['action'] ?? 'draft') === 'post';
        if ($postNow) {
            Gate::authorize('sales.post');   // before anything is saved
        }

        try {
            $sale = $this->service->createDraft($data, $data['items']);
        } catch (DomainException $e) {
            return back()->withInput()->withErrors(['sale' => $e->getMessage()]);
        }

        return $this->afterSave($sale, $postNow);
    }

    public function show(Sale $sale): View
    {
        Gate::authorize('sales.view');
        $sale->load(['items.product.unit', 'customer', 'receivedInto', 'journalEntry', 'reversalEntry', 'creator', 'canceller', 'returns', 'invoices.printedBy']);

        return view('sales.show', ['sale' => $sale, 'summary' => $sale->summary()]);
    }

    public function edit(Sale $sale): View|RedirectResponse
    {
        Gate::authorize('sales.update');

        if (! $sale->isDraft()) {
            return redirect()->route('sales.show', $sale)->with('error', 'Only a draft can be edited. Cancel a posted invoice and enter it again.');
        }

        return view('sales.form', $this->formData($sale->load('items')));
    }

    public function update(SaleRequest $request, Sale $sale): RedirectResponse
    {
        $data = $request->validated();
        $postNow = ($data['action'] ?? 'draft') === 'post';
        if ($postNow) {
            Gate::authorize('sales.post');
        }

        try {
            $sale = $this->service->updateDraft($sale, $data, $data['items']);
        } catch (DomainException $e) {
            return back()->withInput()->withErrors(['sale' => $e->getMessage()]);
        }

        return $this->afterSave($sale, $postNow);
    }

    public function post(Sale $sale): RedirectResponse
    {
        Gate::authorize('sales.post');

        try {
            $this->service->post($sale);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Invoice posted.');
    }

    public function cancel(Request $request, Sale $sale): RedirectResponse
    {
        Gate::authorize('sales.cancel');
        $request->validate(['reason' => ['required', 'string', 'max:200']]);

        try {
            $this->service->cancel($sale, $request->string('reason')->toString());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Invoice cancelled. Stock and accounts have been reversed.');
    }

    /** Drafts only. A posted invoice is cancelled, never deleted. */
    public function destroy(Sale $sale): RedirectResponse
    {
        Gate::authorize('sales.update');

        if (! $sale->isDraft()) {
            return back()->with('error', 'Only a draft can be deleted. Cancel a posted invoice instead.');
        }

        DB::transaction(function () use ($sale) {
            $sale->items()->delete();
            $sale->delete();
        });

        return redirect()->route('sales.index')->with('success', 'Draft deleted.');
    }

    private function afterSave(Sale $sale, bool $postNow): RedirectResponse
    {
        if ($postNow) {
            try {
                $sale = $this->service->post($sale);
            } catch (DomainException $e) {
                // the draft is safe; tell the user why it was not posted
                return redirect()->route('sales.show', $sale)->with('error', "Saved as a draft but not posted: {$e->getMessage()}");
            }
        }

        return redirect()->route('sales.show', $sale)->with('success', ($postNow ? "Invoice {$sale->invoice_no} posted." : 'Saved as a draft.'));
    }

    private function formData(Sale $sale): array
    {
        return [
            'sale' => $sale,
            'customers' => Customer::where('is_active', true)->orderBy('name')->get(),
            'accounts' => Account::active()->whereIn('sub_type', ['cash', 'bank'])->orderBy('code')->get(['id', 'code', 'name']),
            'products' => Product::where('is_active', true)->with('unit')->orderBy('name')->get(),
            // quantity on hand per product, shown next to the quantity box
            'stock' => InventoryTransaction::whereIn('id', InventoryTransaction::query()->selectRaw('MAX(id)')->groupBy('product_id'))->pluck('balance_qty', 'product_id'),
        ];
    }
}
