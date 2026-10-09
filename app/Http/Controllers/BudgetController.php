<?php

namespace App\Http\Controllers;

use App\Actions\SaveBudget;
use App\Http\Requests\StoreBudgetRequest;
use App\Models\Budget;
use App\Models\Category;
use App\Queries\LegacyPageRelations;
use App\Queries\LegacyRelationIntegrity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class BudgetController extends Controller
{
    public function index(Request $request, LegacyPageRelations $relations)
    {
        try {
            $budgets = $relations->budgets($request->user());
        } catch (LegacyRelationIntegrity) {
            return response()->view('errors.legacy-relation-integrity', [], 409);
        }
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
