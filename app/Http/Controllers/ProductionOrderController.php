<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductionOrderRequest;
use App\Models\Account;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\Supplier;
use App\Services\Production\ProductionOrderService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ProductionOrderController extends Controller
{
    public function __construct(private ProductionOrderService $service) {}

    public function index(Request $request): View
    {
        Gate::authorize('production_orders.view');
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'status' => ['nullable', 'in:draft,posted,cancelled']]);

        return view('production-orders.index', [
            'orders' => ProductionOrder::with(['processor', 'outputs.product'])
                ->when($request->status, fn ($query, $value) => $query->where('status', $value))
                ->when($request->from, fn ($query, $value) => $query->whereDate('order_date', '>=', $value))
                ->when($request->to, fn ($query, $value) => $query->whereDate('order_date', '<=', $value))
                ->orderByDesc('order_date')->orderByDesc('id')->paginate(20)->withQueryString(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('production_orders.create');

        return view('production-orders.create', [
            'products' => Product::where('is_active', true)->with('unit')->orderBy('name')->get(),
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'cashAccounts' => Account::active()->whereIn('sub_type', ['cash', 'bank'])->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(StoreProductionOrderRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $postNow = ($data['action'] ?? 'draft') === 'post';
        if ($postNow) {
            Gate::authorize('production_orders.post');
        }

        try {
            $order = $this->service->createDraft($data, $data['inputs']);
        } catch (DomainException $e) {
            return back()->withInput()->withErrors(['production' => $e->getMessage()]);
        }

        if ($postNow) {
            try {
                $this->service->post($order);
            } catch (DomainException $e) {
                return redirect()->route('production-orders.show', $order)->with('error', "Saved as a draft but not posted: {$e->getMessage()}");
            }
        }

        return redirect()->route('production-orders.show', $order)->with('success', "{$order->order_no} ".($postNow ? 'posted.' : 'saved as a draft.'));
    }

    public function show(ProductionOrder $productionOrder): View
    {
        Gate::authorize('production_orders.view');
        $order = $productionOrder->load([
            'inputs.product.unit', 'outputs.product.unit', 'processor', 'paidFromAccount',
            'journalEntry', 'reversalEntry', 'creator', 'canceller',
        ]);

        return view('production-orders.show', [
            'order' => $order,
            'movements' => InventoryTransaction::with('product')
                ->where('source_type', $order->getMorphClass())
                ->where('source_id', $order->id)
                ->orderBy('id')
                ->get(),
        ]);
    }

    public function post(ProductionOrder $productionOrder): RedirectResponse
    {
        Gate::authorize('production_orders.post');

        try {
            $this->service->post($productionOrder);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Production transaction posted.');
    }

    public function cancel(Request $request, ProductionOrder $productionOrder): RedirectResponse
    {
        Gate::authorize('production_orders.cancel');
        $request->validate(['reason' => ['required', 'string', 'max:200']]);

        try {
            $this->service->cancel($productionOrder, $request->string('reason')->toString());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Production transaction cancelled and reversed.');
    }

    public function destroy(ProductionOrder $productionOrder): RedirectResponse
    {
        Gate::authorize('production_orders.update');

        if (! $productionOrder->isDraft()) {
            return back()->with('error', 'Only a draft production transaction can be deleted. Cancel a posted transaction instead.');
        }

        DB::transaction(function () use ($productionOrder): void {
            $productionOrder->inputs()->delete();
            $productionOrder->outputs()->delete();
            $productionOrder->delete();
        });

        return redirect()->route('production-orders.index')->with('success', 'Draft production transaction deleted.');
    }
}
