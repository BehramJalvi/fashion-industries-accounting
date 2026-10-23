<?php

namespace App\Http\Controllers\Parties;

use App\Http\Controllers\Controller;
use App\Services\Accounting\OpeningBalanceService;
use DomainException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Record or cancel the opening balance of an existing customer / supplier / employee. */
class PartyOpeningController extends Controller
{
    public function __construct(private OpeningBalanceService $opening)
    {
    }

    public function store(Request $request, string $type, string $id): RedirectResponse
    {
        Gate::authorize('journal.create');
        Gate::authorize('journal.post');
        $party = $this->party($type, $id);

        $data = $request->validate([
            'opening_amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999'],
            'opening_side' => ['required', 'in:owes_us,we_owe'],
            'opening_date' => ['required', 'date'],
        ]);

        try {
            $this->opening->record($party, $data['opening_amount'], $data['opening_side'], $data['opening_date']);
        } catch (DomainException $e) {
            return back()->withInput()->withErrors(['opening_amount' => $e->getMessage()]);
        }

        return back()->with('success', 'Opening balance recorded.');
    }

    public function destroy(Request $request, string $type, string $id): RedirectResponse
    {
        Gate::authorize('journal.cancel');
        $party = $this->party($type, $id);

        try {
            $this->opening->cancel($party, $request->input('reason'));
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Opening balance cancelled (a reversing entry was posted).');
    }

    private function party(string $type, string $id)
    {
        $class = Relation::getMorphedModel($type);
        abort_unless($class && in_array($type, ['customer', 'supplier', 'employee'], true), 404);

        $party = $class::findOrFail($id);
        Gate::authorize($type . 's.update');   // customers.update, suppliers.update, employees.update

        return $party;
    }
}
