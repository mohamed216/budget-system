<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCategoryRequest;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        $categories = Category::ownedBy($request->user())->get();

        return view('categories.index', compact('categories'));
    }

    public function store(StoreCategoryRequest $request)
    {
        $request->user()->categories()->create($request->validated());

        return redirect()->route('categories.index')->with('success', 'Category created');
    }

    public function destroy(Category $category)
    {
        Gate::authorize('delete', $category);
        DB::transaction(function () use ($category) {
            $locked = Category::query()->lockForUpdate()->findOrFail($category->id);
            Gate::authorize('delete', $locked);
            if ($locked->transactions()->exists() || $locked->budgets()->exists()) {
                throw ValidationException::withMessages(['category' => 'A category with transactions or budgets cannot be deleted.']);
            }
            $locked->delete();
        });

        return redirect()->route('categories.index')->with('success', 'Category deleted');
    }
}
