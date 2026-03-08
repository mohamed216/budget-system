<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\Category;
use Illuminate\Http\Request;

class BudgetController extends Controller
{
    public function index()
    {
        $budgets = Budget::with('category')->get();
        $categories = Category::where('type', 'expense')->get();
        return view('budgets.index', compact('budgets', 'categories'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'category_id' => 'required',
            'amount' => 'required|numeric',
            'month' => 'required|integer|1:12',
            'year' => 'required|integer'
        ]);

        Budget::updateOrCreate(
            ['category_id' => $request->category_id, 'month' => $request->month, 'year' => $request->year],
            ['amount' => $request->amount]
        );

        return redirect()->route('budgets.index')->with('success', 'Budget saved');
    }

    public function destroy(Budget $budget)
    {
        $budget->delete();
        return redirect()->route('budgets.index')->with('success', 'Budget deleted');
    }
}
