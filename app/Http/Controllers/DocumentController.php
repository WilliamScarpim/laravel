<?php

namespace App\Http\Controllers;

use App\Models\Consultation;
use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DocumentController extends Controller
{
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

        $data = $request->validate([
            'type' => ['required', 'string'],
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
        ]);

        $doc = $consultation->documents()->create($data);

        return response()->json(['data' => $this->transform($doc)], 201);
    }

    public function patch(Request $request, string $consultationId)
    {
        $consultation = Consultation::findOrFail($consultationId);

        $data = $request->validate([
            'id' => ['nullable', 'string'],
            'type' => ['required_without:id', 'string'],
            'title' => ['nullable', 'string', 'max:255'],
            'content' => ['required', 'string'],
        ]);

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

        if (array_key_exists('title', $data) && $data['title'] !== null) {
            $doc->title = $data['title'];
        }

        $doc->content = $data['content'];
        $doc->save();

        return response()->json(['data' => $this->transform($doc)]);
    }

    public function update(Request $request, string $id)
    {
        $doc = Document::findOrFail($id);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'content' => ['required', 'string'],
        ]);

        if (! empty($data['title'])) {
            $doc->title = $data['title'];
        }

        $doc->content = $data['content'];
        $doc->save();

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
}
