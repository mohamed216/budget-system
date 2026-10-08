<?php

namespace App\Http\Controllers;

use App\Actions\SaveBudget;
use App\Http\Requests\StoreBudgetRequest;
use App\Models\Budget;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class BudgetController extends Controller
{
    public function index(Request $request)
    {
        $budgets = Budget::ownedBy($request->user())->with('category')->get();
        $categories = Category::ownedBy($request->user())->where('type', 'expense')->get();

        return view('budgets.index', compact('budgets', 'categories'));
    }

    public function store(StoreBudgetRequest $request, SaveBudget $saveBudget)
    {
        $saveBudget->execute($request->user(), $request->validated());

        return redirect()->route('budgets.index')->with('success', 'Budget saved');
    }

    public function destroy(Budget $budget)
    {
        Gate::authorize('delete', $budget);
        $budget->delete();

        return redirect()->route('budgets.index')->with('success', 'Budget deleted');
    }
}
