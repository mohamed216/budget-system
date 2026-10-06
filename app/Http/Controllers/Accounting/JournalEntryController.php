<?php

namespace App\Http\Controllers\Accounting;

use App\Accounting\Actions\DeleteJournalDraft;
use App\Accounting\Actions\PostJournalEntry;
use App\Accounting\Actions\SaveJournalDraft;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\JournalDraftRequest;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class JournalEntryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', JournalEntry::class);
        $journals = JournalEntry::ownedBy($request->user())->orderByDesc('entry_date')->orderByDesc('id')->get();

        return response()->json(['data' => $journals]);
    }

    public function show(Request $request, string $journal): JsonResponse
    {
        $entry = JournalEntry::ownedBy($request->user())->findOrFail($journal);
        Gate::authorize('view', $entry);

        return response()->json(['data' => $this->withLines($entry, $request->user())]);
    }

    public function store(JournalDraftRequest $request, SaveJournalDraft $action): JsonResponse
    {
        Gate::authorize('create', JournalEntry::class);
        $data = $request->validated();
        $entry = $action->execute($request->user(), $data['entry_date'], $data['currency'], $data['lines'],
            $data['reference'] ?? null, $data['description'] ?? null);

        return response()->json(['data' => $this->withLines($entry, $request->user())], 201);
    }

    public function update(JournalDraftRequest $request, string $journal, SaveJournalDraft $action): JsonResponse
    {
        $entry = JournalEntry::ownedBy($request->user())->findOrFail($journal);
        Gate::authorize('update', $entry);
        $data = $request->validated();
        $entry = $action->execute($request->user(), $data['entry_date'], $data['currency'], $data['lines'],
            $data['reference'] ?? null, $data['description'] ?? null, $entry->id, (int) $data['version']);

        return response()->json(['data' => $this->withLines($entry, $request->user())]);
    }

    public function destroy(Request $request, string $journal, DeleteJournalDraft $action): Response
    {
        $entry = JournalEntry::ownedBy($request->user())->findOrFail($journal);
        Gate::authorize('delete', $entry);
        $action->execute($request->user(), $entry->id);

        return response()->noContent();
    }

    public function post(Request $request, string $journal, PostJournalEntry $action): JsonResponse
    {
        $entry = JournalEntry::ownedBy($request->user())->findOrFail($journal);
        // An already-posted retry is a read, while the actual transition requires post authorization.
        Gate::authorize($entry->isPosted() ? 'view' : 'post', $entry);
        $entry = $action->execute($request->user(), $entry->id);

        return response()->json(['data' => $this->withLines($entry, $request->user())]);
    }

    private function withLines(JournalEntry $entry, User $actor): JournalEntry
    {
        return $entry->refresh()->load([
            'lines' => fn ($query) => $query->ownedBy($actor),
            'lines.chartAccount' => fn ($query) => $query->ownedBy($actor)->select('id', 'user_id', 'code', 'name', 'type', 'is_active'),
        ]);
    }
}
