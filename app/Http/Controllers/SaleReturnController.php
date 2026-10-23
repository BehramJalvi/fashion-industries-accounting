<?php

namespace App\Http\Controllers;

use App\Http\Requests\SalesReturnRequest;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Services\Sales\SaleReturnService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class SaleReturnController extends Controller
{
    public function __construct(private SaleReturnService $service)
    {
    }

    public function index(Request $request): View
    {
        Gate::authorize('sales_returns.view');
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date']);

        return view('sales-returns.index', [
            'returns' => SalesReturn::with(['sale', 'customer'])
                ->when($request->customer_id, fn ($q, $v) => $q->where('customer_id', $v))
                ->when($request->status, fn ($q, $v) => $q->where('status', $v))
                ->when($request->from, fn ($q, $v) => $q->whereDate('return_date', '>=', $v))
                ->when($request->to, fn ($q, $v) => $q->whereDate('return_date', '<=', $v))
                ->orderByDesc('return_date')->orderByDesc('id')->paginate(20)->withQueryString(),
            'customers' => Customer::orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** Step 1: pick a posted invoice. Step 2 (?sale_id=): enter quantities to return. */
    public function create(Request $request): View
    {
        Gate::authorize('sales_returns.create');

        if (! $request->sale_id) {
            return view('sales-returns.choose', ['sales' => Sale::with('customer')->where('status', 'posted')->orderByDesc('sale_date')->orderByDesc('id')->limit(200)->get()]);
        }

        $sale = Sale::with(['items.product.unit', 'customer'])->where('status', 'posted')->findOrFail($request->sale_id);

        return view('sales-returns.create', [
            'sale' => $sale, 'available' => $this->service->returnable($sale),
            'accounts' => Account::active()->whereIn('sub_type', ['cash', 'bank'])->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(SalesReturnRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $postNow = ($data['action'] ?? 'draft') === 'post';
        if ($postNow) {
            Gate::authorize('sales_returns.post');
        }

        $sale = Sale::with('items')->findOrFail($data['sale_id']);

        try {
            $return = $this->service->createDraft($sale, $data, $data['qty']);
        } catch (DomainException $e) {
            return back()->withInput()->withErrors(['return' => $e->getMessage()]);
        }

        if ($postNow) {
            try {
                $this->service->post($return);
            } catch (DomainException $e) {
                return redirect()->route('sales-returns.show', $return)->with('error', "Saved as a draft but not posted: {$e->getMessage()}");
            }
        }

        return redirect()->route('sales-returns.show', $return)->with('success', "{$return->return_no} " . ($postNow ? 'posted.' : 'saved as a draft.'));
    }

    public function show(SalesReturn $salesReturn): View
    {
        Gate::authorize('sales_returns.view');

        return view('sales-returns.show', ['return' => $salesReturn->load(['items.product.unit', 'sale.customer', 'refundAccount', 'journalEntry', 'reversalEntry', 'creator', 'canceller'])]);
    }

    public function post(SalesReturn $salesReturn): RedirectResponse
    {
        Gate::authorize('sales_returns.post');

        try {
            $this->service->post($salesReturn);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Return posted.');
    }

    public function cancel(Request $request, SalesReturn $salesReturn): RedirectResponse
    {
        Gate::authorize('sales_returns.cancel');
        $request->validate(['reason' => ['required', 'string', 'max:200']]);

        try {
            $this->service->cancel($salesReturn, $request->string('reason')->toString());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Return cancelled. Stock and accounts have been reversed.');
    }

    public function destroy(SalesReturn $salesReturn): RedirectResponse
    {
        Gate::authorize('sales_returns.update');

        if (! $salesReturn->isDraft()) {
            return back()->with('error', 'Only a draft can be deleted. Cancel a posted return instead.');
        }

        DB::transaction(function () use ($salesReturn) {
            $salesReturn->items()->delete();
            $salesReturn->delete();
        });

        return redirect()->route('sales-returns.index')->with('success', 'Draft deleted.');
    }
}
