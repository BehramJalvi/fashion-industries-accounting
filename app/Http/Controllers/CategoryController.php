<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CategoryController extends Controller
{
    public function index(): View
    {
        Gate::authorize('categories.view');

        return view('categories.index', ['categories' => Category::withCount('products')->orderBy('name')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('categories.create');
        $data = $request->validate(['name' => ['required', 'string', 'max:100', 'unique:categories,name'], 'description' => ['nullable', 'string', 'max:255']]);
        Category::create($data + ['is_active' => true]);

        return back()->with('success', 'Category added.');
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        Gate::authorize('categories.update');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('categories', 'name')->ignore($category->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $category->update($data + ['is_active' => $request->boolean('is_active')]);

        return back()->with('success', 'Category updated.');
    }

    public function destroy(Category $category): RedirectResponse
    {
        Gate::authorize('categories.delete');

        if ($category->products()->exists()) {
            return back()->with('error', 'This category has products. Mark it inactive instead.');
        }
        $category->delete();

        return back()->with('success', 'Category deleted.');
    }
}
