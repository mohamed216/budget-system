<?php

namespace App\Http\Controllers\Accounting;

use App\Accounting\Actions\CloseFiscalYear;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\FiscalYearCloseRequest;
use App\Models\FiscalYearClose;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FiscalYearCloseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $closes = FiscalYearClose::ownedBy($request->user())->orderBy('start_date')->orderBy('id')->get();

        return response()->json(['data' => $closes->map(fn (FiscalYearClose $close) => $this->serialize($close))->all()]);
    }

    public function store(FiscalYearCloseRequest $request, CloseFiscalYear $action): JsonResponse
    {
        $data = $request->validated();
        $close = $action->execute($request->user(), $data['start_date'], $data['end_date'],
            $data['currency'], $data['retained_earnings_account_id']);

        return response()->json(['data' => $this->serialize($close)], 201);
    }

    public function show(Request $request, string $fiscalYearClose): JsonResponse
    {
        $close = FiscalYearClose::ownedBy($request->user())->findOrFail($fiscalYearClose);

        return response()->json(['data' => $this->serialize($close)]);
    }

    private function serialize(FiscalYearClose $close): array
    {
        return [
            'id' => $close->id,
            'start_date' => $close->start_date->toDateString(),
            'end_date' => $close->end_date->toDateString(),
            'currency' => $close->currency,
            'retained_earnings_account_id' => $close->retained_earnings_account_id,
            'journal_entry_id' => $close->journal_entry_id,
            'closed_at' => $close->closed_at->toISOString(),
        ];
    }
}
