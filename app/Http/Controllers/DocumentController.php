<?php

namespace App\Http\Controllers;

use App\Models\Consultation;
use App\Models\Document;
use App\Services\ConsultationAuditService;
use App\Services\DocumentVersionService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DocumentController extends Controller
{
    public function __construct(
        private readonly ConsultationAuditService $auditService,
        private readonly DocumentVersionService $versionService
    ) {
    }

    public function index(string $consultationId)
    {
        $consultation = Consultation::findOrFail($consultationId);
        $documents = $consultation->documents()->orderByDesc('created_at')->get();

        return response()->json([
            'data' => $documents->map(fn ($d) => $this->transform($d))->values(),
        ]);
    }

    public function store(Request $request, string $consultationId)
    {
        $consultation = Consultation::findOrFail($consultationId);
        $this->assertDocumentsAreEditable($consultation);

        $data = $request->validate([
            'type' => ['required', 'string'],
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
        ]);

        $doc = $consultation->documents()->create($data);

        $userId = $request->user() ? (string) $request->user()->id : null;
        $this->versionService->record($doc, $userId);
        $this->auditService->recordDocument($doc, $userId, 'document_created', [
            ['field' => 'title', 'before' => null, 'after' => $doc->title],
            ['field' => 'content', 'before' => null, 'after' => $doc->content],
        ]);

        return response()->json(['data' => $this->transform($doc)], 201);
    }

    public function patch(Request $request, string $consultationId)
    {
        $consultation = Consultation::findOrFail($consultationId);
        $this->assertDocumentsAreEditable($consultation);

        $data = $request->validate([
            'id' => ['nullable', 'string'],
            'type' => ['required_without:id', 'string'],
            'title' => ['nullable', 'string', 'max:255'],
            'content' => ['required', 'string'],
        ]);

        $userId = $request->user() ? (string) $request->user()->id : null;

        if (! empty($data['id'])) {
            $doc = $consultation->documents()->where('id', $data['id'])->firstOrFail();
        } else {
            $doc = $consultation->documents()->where('type', $data['type'])->first();
            if (! $doc) {
                $doc = $consultation->documents()->create([
                    'type' => $data['type'],
                    'title' => $data['title'] ?? Str::of($data['type'])->replace('_', ' ')->title()->value(),
                    'content' => ''
                ]);
            }
        }

        $isNew = $doc->wasRecentlyCreated;
        $original = $isNew ? ['title' => null, 'content' => null] : $doc->getOriginal();

        if (array_key_exists('title', $data) && $data['title'] !== null) {
            $doc->title = $data['title'];
        }

        $doc->content = $data['content'];
        $hasChanges = $doc->isDirty(['title', 'content']);

        if ($hasChanges) {
            $doc->save();
            $changes = $this->diffDocumentChanges($doc, $original);
            $action = $isNew ? 'document_created' : 'document_updated';

            $this->versionService->record($doc, $userId);
            $this->auditService->recordDocument($doc, $userId, $action, $changes);
        } else {
            $doc->save();
        }

        return response()->json(['data' => $this->transform($doc)]);
    }

    public function update(Request $request, string $id)
    {
        $doc = Document::findOrFail($id);
        $doc->loadMissing('consultation');
        $consultation = $doc->consultation ?? Consultation::findOrFail($doc->consultation_id);
        $this->assertDocumentsAreEditable($consultation);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'content' => ['required', 'string'],
        ]);

        $userId = $request->user() ? (string) $request->user()->id : null;
        $original = $doc->getOriginal();

        if (! empty($data['title'])) {
            $doc->title = $data['title'];
        }

        $doc->content = $data['content'];
        $hasChanges = $doc->isDirty(['title', 'content']);
        $doc->save();

        if ($hasChanges) {
            $this->versionService->record($doc, $userId);
            $this->auditService->recordDocument($doc, $userId, 'document_updated', $this->diffDocumentChanges($doc, $original));
        }

        return response()->json(['data' => $this->transform($doc)]);
    }

    private function transform(Document $doc): array
    {
        return [
            'id' => (string) $doc->id,
            'consultationId' => (string) $doc->consultation_id,
            'type' => $doc->type,
            'title' => $doc->title,
            'content' => $doc->content,
            'createdAt' => optional($doc->created_at)->toIso8601String(),
            'updatedAt' => optional($doc->updated_at)->toIso8601String(),
        ];
    }

    private function diffDocumentChanges(Document $doc, array $original): array
    {
        $fields = ['title', 'content'];
        $changes = [];

        foreach ($fields as $field) {
            $before = $original[$field] ?? null;
            $after = $doc->{$field};

            if ($before === $after) {
                continue;
            }

            $changes[] = [
                'field' => $field,
                'before' => $before,
                'after' => $after,
            ];
        }

        return $changes;
    }

    private function assertDocumentsAreEditable(Consultation $consultation): void
    {
        if ($this->documentsAreLocked($consultation)) {
            abort(403, 'Documentos bloqueados para esta consulta.');
        }
    }

    private function documentsAreLocked(Consultation $consultation): bool
    {
        if ($consultation->status !== 'completed') {
            return false;
        }

        if (! $consultation->completed_at) {
            return false;
        }

        return $consultation->completed_at->lt(now()->subDay());
    }
}
