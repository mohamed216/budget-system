<?php

namespace App\Http\Controllers\Accounting;

use App\Accounting\Queries\GeneralLedgerQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\GeneralLedgerRequest;
use App\Models\ChartAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class GeneralLedgerController extends Controller
{
    public function __invoke(GeneralLedgerRequest $request, GeneralLedgerQuery $query): JsonResponse
    {
        $data = $request->validated();
        $account = ChartAccount::ownedBy($request->user())->findOrFail($data['chart_account_id']);
        Gate::authorize('view', $account);

        return response()->json(['data' => $query->execute($request->user(), $account->id, $data['date_from'] ?? null, $data['date_to'] ?? null)]);
    }
}
