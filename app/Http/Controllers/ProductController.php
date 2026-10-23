<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\Category;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\Unit;
use App\Services\Inventory\OpeningStockService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function __construct(private OpeningStockService $opening)
    {
    }

    public function index(Request $request): View
    {
        Gate::authorize('products.view');

        // Latest ledger row per product carries quantity, average cost and value; no stored stock column exists.
        $last = InventoryTransaction::whereIn('id', InventoryTransaction::query()->selectRaw('MAX(id)')->groupBy('product_id'))
            ->get()->keyBy('product_id');

        $query = Product::with(['category', 'unit'])
            ->when($request->q, fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'like', "%$t%")->orWhere('code', 'like', "%$t%")))
            ->when($request->category_id, fn ($q, $v) => $q->where('category_id', $v))
            ->when($request->status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($request->status === 'inactive', fn ($q) => $q->where('is_active', false));

        if ($request->boolean('low')) {
            $lowIds = Product::where('is_active', true)->get(['id', 'min_stock'])
                ->filter(fn ($p) => (float) ($last[$p->id]->balance_qty ?? 0) < (float) $p->min_stock)->pluck('id');
            $query->whereIn('id', $lowIds);
        }

        return view('products.index', [
            'products' => $query->orderBy('name')->paginate(20)->withQueryString(),
            'last' => $last,
            'categories' => Category::orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('products.create');

        return view('products.form', $this->formData(new Product(['is_active' => true, 'product_type' => 'trading'])));
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        $data = $request->validated();

        try {
            $product = DB::transaction(function () use ($data) {
                $product = Product::create($this->attributes($data) + ['code' => ($data['code'] ?? null) ?: $this->nextCode()]);

                if (! empty($data['opening_qty'])) {
                    $this->opening->record($product, $data['opening_qty'], $data['opening_unit_cost'], $data['opening_date']);
                }

                return $product;
            });
        } catch (DomainException $e) {
            return back()->withInput()->withErrors(['opening_qty' => $e->getMessage()]);
        }

        if ($request->hasFile('image')) {
            $product->update(['image_path' => $request->file('image')->store('products', 'public')]);   // random server-side name
        }

        return redirect()->route('products.index')->with('success', "Product {$product->name} created.");
    }

    public function edit(Product $product): View
    {
        Gate::authorize('products.update');

        return view('products.form', $this->formData($product));
    }

    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        $data = $request->validated();
        $product->fill($this->attributes($data) + (! empty($data['code']) ? ['code' => $data['code']] : []));

        if ($request->hasFile('image')) {
            $this->deleteImage($product);
            $product->image_path = $request->file('image')->store('products', 'public');
        } elseif ($request->boolean('remove_image')) {
            $this->deleteImage($product);
            $product->image_path = null;
        }
        $product->save();

        return redirect()->route('products.index')->with('success', 'Product updated.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        Gate::authorize('products.delete');

        if ($product->inventoryTransactions()->exists()) {
            return back()->with('error', 'This product has stock history and cannot be deleted. Mark it inactive instead.');
        }

        $this->deleteImage($product);
        $product->delete();

        return redirect()->route('products.index')->with('success', 'Product deleted.');
    }

    private function attributes(array $data): array
    {
        return [
            'name' => $data['name'], 'category_id' => $data['category_id'] ?? null, 'unit_id' => $data['unit_id'],
            'product_type' => $data['product_type'], 'purchase_price' => $data['purchase_price'], 'selling_price' => $data['selling_price'],
            'default_tax_rate' => $data['default_tax_rate'] ?? null, 'min_stock' => $data['min_stock'] ?? 0,
            'description' => $data['description'] ?? null, 'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    private function formData(Product $product): array
    {
        return ['product' => $product, 'categories' => Category::where('is_active', true)->orderBy('name')->get(), 'units' => Unit::orderBy('name')->get()];
    }

    private function nextCode(): string
    {
        $n = (int) Product::withTrashed()->max('id') + 1;
        do {
            $code = 'P' . str_pad((string) $n++, 4, '0', STR_PAD_LEFT);
        } while (Product::withTrashed()->where('code', $code)->exists());

        return $code;
    }

    private function deleteImage(Product $product): void
    {
        if ($product->image_path) {
            Storage::disk('public')->delete($product->image_path);
        }
    }
}
