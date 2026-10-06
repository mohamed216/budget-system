<?php

namespace App\Http\Controllers\Accounting;

use App\Accounting\Queries\TrialBalanceQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\TrialBalanceRequest;
use App\Models\ChartAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class TrialBalanceController extends Controller
{
    public function __invoke(TrialBalanceRequest $request, TrialBalanceQuery $query): JsonResponse
    {
        Gate::authorize('viewAny', ChartAccount::class);
        $data = $request->validated();

        return response()->json(['data' => $query->execute($request->user(), $data['as_of'] ?? null)]);
    }
}
