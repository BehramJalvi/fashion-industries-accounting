<?php

namespace App\Http\Controllers;

use App\Models\Unit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Units of measure share the categories.* permissions. */
class UnitController extends Controller
{
    public function index(): View
    {
        Gate::authorize('categories.view');

        return view('units.index', ['units' => Unit::withCount('products')->orderBy('name')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('categories.create');
        Unit::create($request->validate(['name' => ['required', 'string', 'max:50', 'unique:units,name'], 'short_name' => ['nullable', 'string', 'max:15']]));

        return back()->with('success', 'Unit added.');
    }

    public function update(Request $request, Unit $unit): RedirectResponse
    {
        Gate::authorize('categories.update');
        $unit->update($request->validate([
            'name' => ['required', 'string', 'max:50', Rule::unique('units', 'name')->ignore($unit->id)], 'short_name' => ['nullable', 'string', 'max:15'],
        ]));

        return back()->with('success', 'Unit updated.');
    }

    public function destroy(Unit $unit): RedirectResponse
    {
        Gate::authorize('categories.delete');

        if ($unit->products()->exists()) {
            return back()->with('error', 'This unit is used by products and cannot be deleted.');
        }
        $unit->delete();

        return back()->with('success', 'Unit deleted.');
    }
}
