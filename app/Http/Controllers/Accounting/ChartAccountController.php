<?php

namespace App\Http\Controllers\Accounting;

use App\Accounting\Actions\CreateChartAccount;
use App\Accounting\Actions\DeleteChartAccount;
use App\Accounting\Actions\UpdateChartAccount;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\ChartAccountRequest;
use App\Models\ChartAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class ChartAccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', ChartAccount::class);
        $actor = $request->user();
        $accounts = ChartAccount::ownedBy($actor)->with([
            'parent' => fn ($query) => $query->ownedBy($actor)->select('id', 'user_id', 'code', 'name'),
            'children' => fn ($query) => $query->ownedBy($actor)->orderBy('code')->orderBy('id'),
        ])->orderBy('code')->orderBy('id')->get();

        return response()->json(['data' => $accounts]);
    }

    public function store(ChartAccountRequest $request, CreateChartAccount $action): JsonResponse
    {
        Gate::authorize('create', ChartAccount::class);
        $data = $request->validated();
        $account = $action->execute($request->user(), $data['code'], $data['name'], $data['type'],
            (bool) $data['is_active'], isset($data['parent_id']) ? (int) $data['parent_id'] : null);

        return response()->json(['data' => $account], 201);
    }

    public function update(ChartAccountRequest $request, string $chartAccount, UpdateChartAccount $action): JsonResponse
    {
        $account = ChartAccount::ownedBy($request->user())->findOrFail($chartAccount);
        Gate::authorize('update', $account);
        $data = $request->validated();
        $account = $action->execute($request->user(), $account->id, $data['code'], $data['name'], $data['type'],
            (bool) $data['is_active'], isset($data['parent_id']) ? (int) $data['parent_id'] : null);

        return response()->json(['data' => $account]);
    }

    public function destroy(Request $request, string $chartAccount, DeleteChartAccount $action): Response
    {
        $account = ChartAccount::ownedBy($request->user())->findOrFail($chartAccount);
        Gate::authorize('delete', $account);
        $action->execute($request->user(), $account->id);

        return response()->noContent();
    }
}
