<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreStockAdjustmentRequest;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Services\Inventory\StockAdjustmentService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class StockAdjustmentController extends Controller
{
    public function __construct(private StockAdjustmentService $service)
    {
    }

    public function index(Request $request): View
    {
        Gate::authorize('stock_adjustments.view');

        return view('stock-adjustments.index', [
            'adjustments' => StockAdjustment::withCount('items')
                ->when($request->status, fn ($q, $v) => $q->where('status', $v))
                ->orderByDesc('adjustment_date')->orderByDesc('id')->paginate(20)->withQueryString(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('stock_adjustments.create');

        return view('stock-adjustments.create', ['products' => Product::where('is_active', true)->with('unit')->orderBy('name')->get()]);
    }

    public function store(StoreStockAdjustmentRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $postNow = ($data['action'] ?? 'draft') === 'post';
        if ($postNow) {
            Gate::authorize('stock_adjustments.post');   // checked BEFORE anything is saved
        }

        try {
            $adjustment = $this->service->createDraft(
                ['adjustment_date' => $data['adjustment_date'], 'reason' => $data['reason'], 'notes' => $data['notes'] ?? null],
                $data['items']
            );
            if ($postNow) {
                $this->service->post($adjustment);
            }
        } catch (DomainException $e) {
            return back()->withInput()->withErrors(['items' => $e->getMessage()]);
        }

        return redirect()->route('stock-adjustments.show', $adjustment)->with('success', "Stock adjustment {$adjustment->adjustment_no} saved.");
    }

    public function show(StockAdjustment $stockAdjustment): View
    {
        Gate::authorize('stock_adjustments.view');

        return view('stock-adjustments.show', [
            'adj' => $stockAdjustment->load(['items.product.unit', 'journalEntry', 'reversalEntry', 'creator', 'canceller']),
            'movements' => InventoryTransaction::with('product')
                ->where('source_type', $stockAdjustment->getMorphClass())->where('source_id', $stockAdjustment->id)->orderBy('id')->get(),
        ]);
    }

    public function post(StockAdjustment $stockAdjustment): RedirectResponse
    {
        Gate::authorize('stock_adjustments.post');

        try {
            $this->service->post($stockAdjustment);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Stock adjustment posted.');
    }

    public function cancel(Request $request, StockAdjustment $stockAdjustment): RedirectResponse
    {
        Gate::authorize('stock_adjustments.cancel');
        $request->validate(['reason' => ['required', 'string', 'max:200']]);

        try {
            $this->service->cancel($stockAdjustment, $request->string('reason')->toString());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Stock adjustment cancelled and reversed.');
    }

    /** Only drafts can be deleted; posted ones are cancelled. */
    public function destroy(StockAdjustment $stockAdjustment): RedirectResponse
    {
        Gate::authorize('stock_adjustments.update');

        if (! $stockAdjustment->isDraft()) {
            return back()->with('error', 'Only a draft can be deleted. Cancel a posted adjustment instead.');
        }

        DB::transaction(function () use ($stockAdjustment) {
            $stockAdjustment->items()->delete();
            $stockAdjustment->delete();
        });

        return redirect()->route('stock-adjustments.index')->with('success', 'Draft deleted.');
    }
}
