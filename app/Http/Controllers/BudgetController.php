<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBudgetRequest;
use App\Models\Budget;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class BudgetController extends Controller
{
    public function index(Request $request)
    {
        $budgets = Budget::ownedBy($request->user())->with('category')->get();
        $categories = Category::ownedBy($request->user())->where('type', 'expense')->get();

        return view('budgets.index', compact('budgets', 'categories'));
    }

    public function store(StoreBudgetRequest $request)
    {
        $data = $request->validated();
        DB::transaction(function () use ($request, $data) {
            Category::ownedBy($request->user())->where('type', 'expense')->lockForUpdate()->findOrFail($data['category_id']);
            // An unassigned legacy budget must not be claimed through updateOrCreate.
            $existing = Budget::where('category_id', $data['category_id'])
                ->where('month', $data['month'])->where('year', $data['year'])->first();
            if ($existing) {
                Gate::authorize('view', $existing);
            }
            $request->user()->budgets()->updateOrCreate(
                ['category_id' => $data['category_id'], 'month' => $data['month'], 'year' => $data['year']],
                ['amount' => $data['amount']]
            );
        }, 3);

        return redirect()->route('budgets.index')->with('success', 'Budget saved');
    }

    public function destroy(Budget $budget)
    {
        Gate::authorize('delete', $budget);
        $budget->delete();

        return redirect()->route('budgets.index')->with('success', 'Budget deleted');
    }
}
