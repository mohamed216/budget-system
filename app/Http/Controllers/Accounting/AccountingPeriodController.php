<?php

namespace App\Http\Controllers\Accounting;

use App\Accounting\Actions\CloseAccountingPeriod;
use App\Accounting\Actions\CreateAccountingPeriod;
use App\Accounting\Actions\DeleteAccountingPeriod;
use App\Accounting\Actions\ReopenAccountingPeriod;
use App\Accounting\Actions\UpdateAccountingPeriod;
use App\Accounting\Exceptions\AccountingConflict;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\AccountingPeriodRequest;
use App\Models\AccountingPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class AccountingPeriodController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', AccountingPeriod::class);
        $periods = AccountingPeriod::ownedBy($request->user())->orderBy('start_date')->orderBy('id')->get();

        return response()->json(['data' => $periods->map(fn (AccountingPeriod $period) => $this->serialize($period))->all()]);
    }

    public function show(Request $request, string $period): JsonResponse
    {
        $record = $this->owned($request, $period);
        Gate::authorize('view', $record);

        return response()->json(['data' => $this->serialize($record)]);
    }

    public function store(AccountingPeriodRequest $request, CreateAccountingPeriod $action): JsonResponse
    {
        Gate::authorize('create', AccountingPeriod::class);
        $data = $request->validated();
        $period = $action->execute($request->user(), $data['start_date'], $data['end_date']);

        return response()->json(['data' => $this->serialize($period)], 201);
    }

    public function update(AccountingPeriodRequest $request, string $period, UpdateAccountingPeriod $action): JsonResponse
    {
        $record = $this->owned($request, $period);
        $this->authorizeMutation('update', $record);
        $data = $request->validated();
        $record = $action->execute($request->user(), $record->id, $data['start_date'], $data['end_date']);

        return response()->json(['data' => $this->serialize($record)]);
    }

    public function close(Request $request, string $period, CloseAccountingPeriod $action): JsonResponse
    {
        $record = $this->owned($request, $period);
        $this->authorizeMutation('close', $record);

        return response()->json(['data' => $this->serialize($action->execute($request->user(), $record->id))]);
    }

    public function reopen(Request $request, string $period, ReopenAccountingPeriod $action): JsonResponse
    {
        $record = $this->owned($request, $period);
        $this->authorizeMutation('reopen', $record);

        return response()->json(['data' => $this->serialize($action->execute($request->user(), $record->id))]);
    }

    public function destroy(Request $request, string $period, DeleteAccountingPeriod $action): Response
    {
        $record = $this->owned($request, $period);
        $this->authorizeMutation('delete', $record);
        $action->execute($request->user(), $record->id);

        return response()->noContent();
    }

    private function owned(Request $request, string $id): AccountingPeriod
    {
        return AccountingPeriod::ownedBy($request->user())->findOrFail($id);
    }

    private function authorizeMutation(string $ability, AccountingPeriod $period): void
    {
        Gate::authorize('view', $period);
        if (Gate::inspect($ability, $period)->denied()) {
            throw new AccountingConflict('Accounting period is not eligible for '.$ability.'.');
        }
    }

    private function serialize(AccountingPeriod $period): array
    {
        return [
            'id' => $period->id,
            'start_date' => $period->start_date->format('Y-m-d'),
            'end_date' => $period->end_date->format('Y-m-d'),
            'status' => $period->status,
            'first_closed_at' => $period->first_closed_at?->toISOString(),
            'created_at' => $period->created_at?->toISOString(),
            'updated_at' => $period->updated_at?->toISOString(),
        ];
    }
}
