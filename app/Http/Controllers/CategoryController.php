<?php

namespace App\Http\Controllers;

use App\Actions\DeleteCategory;
use App\Actions\Exceptions\CategoryHasReferences;
use App\Http\Requests\StoreCategoryRequest;
use App\Models\Category;
use Illuminate\Http\Request;
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

    public function destroy(Request $request, Category $category, DeleteCategory $deleteCategory)
    {
        try {
            $deleteCategory->execute($request->user(), $category);
        } catch (CategoryHasReferences) {
            throw ValidationException::withMessages(['category' => 'A category with transactions or budgets cannot be deleted.']);
        }

        return redirect()->route('categories.index')->with('success', 'Category deleted');
    }
}
