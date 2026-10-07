<?php

namespace App\Http\Controllers\Accounting;

use App\Accounting\Actions\CreateOpeningBalanceDraft;
use App\Accounting\Actions\DeleteOpeningBalanceDraft;
use App\Accounting\Actions\PostOpeningBalanceBatch;
use App\Accounting\Actions\UpdateOpeningBalanceDraft;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\OpeningBalanceDraftRequest;
use App\Models\OpeningBalanceBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class OpeningBalanceController extends Controller
{
    public function store(OpeningBalanceDraftRequest $request, CreateOpeningBalanceDraft $action): JsonResponse
    {
        $data = $request->validated();
        $batch = $action->execute($request->user(), $data['opening_date'], $data['currency'], $data['lines']);

        return response()->json(['data' => $this->serialize($batch)], 201);
    }

    public function show(Request $request, string $openingBalance): JsonResponse
    {
        return response()->json(['data' => $this->serialize($this->owned($request, $openingBalance))]);
    }

    public function update(OpeningBalanceDraftRequest $request, string $openingBalance, UpdateOpeningBalanceDraft $action): JsonResponse
    {
        $batch = $this->owned($request, $openingBalance);
        $data = $request->validated();
        $updated = $action->execute($request->user(), $batch->id, $data['opening_date'], $data['currency'], $data['lines']);

        return response()->json(['data' => $this->serialize($updated)]);
    }

    public function destroy(Request $request, string $openingBalance, DeleteOpeningBalanceDraft $action): Response
    {
        $batch = $this->owned($request, $openingBalance);
        $action->execute($request->user(), $batch->id);

        return response()->noContent();
    }

    public function post(Request $request, string $openingBalance, PostOpeningBalanceBatch $action): JsonResponse
    {
        $batch = $this->owned($request, $openingBalance);
        $posted = $action->execute($request->user(), $batch->id);

        return response()->json(['data' => $this->serialize($posted)]);
    }

    private function owned(Request $request, string $id): OpeningBalanceBatch
    {
        return OpeningBalanceBatch::ownedBy($request->user())->findOrFail($id);
    }

    private function serialize(OpeningBalanceBatch $batch): array
    {
        $batch->load('lines');

        return [
            'id' => $batch->id,
            'opening_date' => $batch->opening_date->toDateString(),
            'currency' => $batch->currency,
            'status' => $batch->status,
            'journal_entry_id' => $batch->journal_entry_id,
            'posted_at' => $batch->posted_at?->toISOString(),
            'lines' => $batch->lines->map(fn ($line) => [
                'chart_account_id' => $line->chart_account_id,
                'debit' => $line->debit,
                'credit' => $line->credit,
            ])->all(),
        ];
    }
}
