<?php

namespace App\Http\Controllers\Parties;

use App\Http\Controllers\Controller;
use App\Models\JournalEntryLine;
use App\Services\Accounting\OpeningBalanceService;
use App\Services\Accounting\PartyLedger;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Shared CRUD + ledger for the four party types (customers, suppliers, employees, owners).
 * Subclasses only describe their model, fields and validation. Balances are always derived from the journal.
 * Permissions come from the prefix: customers.view / .create / .update / .delete.
 */
abstract class PartyController extends Controller
{
    public function __construct(protected PartyLedger $ledger, protected OpeningBalanceService $opening)
    {
    }

    /** @return class-string<Model> */
    abstract protected function model(): string;
    abstract protected function type(): string;                 // morph alias: customer | supplier | employee | owner
    abstract protected function prefix(): string;               // route + permission prefix: customers
    abstract protected function labels(): array;                // singular, plural, balance, positive, negative, sides
    abstract protected function columns(): array;               // list columns: header => attribute
    abstract protected function fields(): array;                // form fields: name,label,type,cols,required,default
    abstract protected function rules(?int $ignoreId): array;

    protected function codePrefix(): ?string { return null; }
    protected function historyRelations(): array { return []; }

    public function index(Request $request): View
    {
        Gate::authorize($this->perm('view'));
        $model = $this->model();

        $items = $model::query()
            ->when($request->q, fn ($q, $t) => $q->where(function ($w) use ($t) {
                $w->where('name', 'like', "%$t%");
                if ($this->codePrefix()) {
                    $w->orWhere('code', 'like', "%$t%");
                }
            }))
            ->when($request->status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($request->status === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('name')->paginate(20)->withQueryString();

        $balances = $this->balances();

        return view('parties.index', [
            'items' => $items, 'balances' => $balances, 'total' => array_sum($balances), 'cfg' => $this->cfg(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize($this->perm('create'));

        $defaults = ['is_active' => true];
        foreach ($this->fields() as $f) {
            if (array_key_exists('default', $f)) {
                $defaults[$f['name']] = $f['default'];
            }
        }

        return view('parties.form', $this->formData(new ($this->model())($defaults)));
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize($this->perm('create'));
        $data = $request->validate($this->rules(null) + $this->openingRules());

        $opening = $this->wantsOpening($data);
        if ($opening) {
            Gate::authorize('journal.create');    // an opening balance is a journal entry
            Gate::authorize('journal.post');
        }

        try {
            $party = DB::transaction(function () use ($data, $request, $opening) {
                $model = $this->model();
                $party = $model::create($this->attributes($data, $request));
                if ($opening) {
                    $this->opening->record($party, $data['opening_amount'], $data['opening_side'], $data['opening_date']);
                }

                return $party;
            });
        } catch (DomainException $e) {
            return back()->withInput()->withErrors(['opening_amount' => $e->getMessage()]);
        }

        return redirect()->route($this->prefix() . '.index')->with('success', "{$this->labels()['singular']} {$party->name} created.");
    }

    public function edit(string $id): View
    {
        Gate::authorize($this->perm('update'));

        return view('parties.form', $this->formData($this->find($id)));
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        Gate::authorize($this->perm('update'));
        $party = $this->find($id);
        $data = $request->validate($this->rules($party->getKey()));

        $party->update($this->attributes($data, $request, $party));

        return redirect()->route($this->prefix() . '.index')->with('success', 'Saved.');
    }

    /** Parties with any history are deactivated, never deleted. */
    public function destroy(string $id): RedirectResponse
    {
        Gate::authorize($this->perm('delete'));
        $party = $this->find($id);

        if ($this->hasHistory($party)) {
            return back()->with('error', 'This record has transactions and cannot be deleted. Mark it inactive instead.');
        }
        $party->delete();

        return redirect()->route($this->prefix() . '.index')->with('success', 'Deleted.');
    }

    public function ledger(Request $request, string $id): View
    {
        Gate::authorize($this->perm('view'));
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date']);
        $party = $this->find($id);

        return view('parties.ledger', [
            'party' => $party, 'cfg' => $this->cfg(), 'statement' => $this->ledger->statement($party, $request->from, $request->to),
        ]);
    }

    // ------------------------------------------------------------------ helpers

    protected function perm(string $action): string
    {
        return $this->prefix() . '.' . $action;
    }

    protected function find(string $id): Model
    {
        $model = $this->model();

        return $model::findOrFail($id);
    }

    protected function cfg(): array
    {
        return ['prefix' => $this->prefix(), 'type' => $this->type(), 'labels' => $this->labels(), 'columns' => $this->columns(),
                'hasCode' => $this->codePrefix() !== null, 'supportsOpening' => $this->opening->supports(new ($this->model())) ];
    }

    private function formData(Model $party): array
    {
        return [
            'party' => $party, 'cfg' => $this->cfg(), 'fields' => $this->fields(),
            'openingEntry' => $party->exists && $this->opening->supports($party) ? $this->opening->existing($party) : null,
        ];
    }

    private function balances(): array
    {
        $proto = new ($this->model());

        return $this->ledger->balances($this->type(), $proto->ledgerControlCodes(), $proto->ledgerNature());
    }

    private function hasHistory(Model $party): bool
    {
        if (JournalEntryLine::where('party_type', $party->getMorphClass())->where('party_id', $party->getKey())->exists()) {
            return true;
        }
        foreach ($this->historyRelations() as $relation) {
            if ($party->{$relation}()->exists()) {
                return true;
            }
        }

        return false;
    }

    private function openingRules(): array
    {
        if (! $this->opening->supports(new ($this->model()))) {
            return [];
        }

        return [
            'opening_amount' => ['nullable', 'numeric', 'gt:0', 'max:9999999999999'],
            'opening_side' => ['required_with:opening_amount', 'nullable', 'in:owes_us,we_owe'],
            'opening_date' => ['required_with:opening_amount', 'nullable', 'date'],
        ];
    }

    private function wantsOpening(array $data): bool
    {
        return ! empty($data['opening_amount']);
    }

    /** Only declared fields are ever mass-assigned; checkboxes become booleans; blanks fall back to the field default. */
    private function attributes(array $data, Request $request, ?Model $existing = null): array
    {
        $attrs = [];
        foreach ($this->fields() as $f) {
            $name = $f['name'];
            if ($f['type'] === 'checkbox') {
                $attrs[$name] = $request->boolean($name);
                continue;
            }
            $value = $data[$name] ?? null;
            $attrs[$name] = ($value === null && array_key_exists('default', $f)) ? $f['default'] : $value;
        }

        if ($this->codePrefix() && empty($attrs['code'])) {
            $attrs['code'] = $existing?->code ?: $this->nextCode();
        }

        return $attrs;
    }

    private function nextCode(): string
    {
        $model = $this->model();
        $prefix = $this->codePrefix();
        $max = $model::withTrashed()->where('code', 'like', $prefix . '%')->pluck('code')
            ->map(fn ($c) => (int) substr($c, strlen($prefix)))->max() ?? 0;

        return $prefix . str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }
}
