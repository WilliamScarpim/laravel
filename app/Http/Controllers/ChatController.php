<?php

namespace App\Http\Controllers;

use App\Models\Consultation;
use App\Services\AiAnamnesisService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ChatController extends Controller
{
    public function __construct(private readonly AiAnamnesisService $aiService)
    {
    }

    public function respond(Request $request)
    {
        $data = $request->validate([
            'consultationId' => ['required', 'string', 'exists:consultations,id'],
            'messages' => ['required', 'array', 'min:1'],
            'messages.*.role' => ['required', 'string', 'in:user,assistant'],
            'messages.*.content' => ['required', 'string'],
        ]);

        $consultation = Consultation::with(['patient', 'documents' => fn ($query) => $query->orderBy('created_at')])
            ->findOrFail($data['consultationId']);

        $messages = collect($data['messages'])
            ->map(function ($message) {
                $role = $message['role'] ?? null;
                $content = isset($message['content']) ? trim((string) $message['content']) : '';

                if (! in_array($role, ['user', 'assistant'], true) || $content === '') {
                    return null;
                }

                return [
                    'role' => $role,
                    'content' => $content,
                ];
            })
            ->filter()
            ->values();

        if ($messages->isEmpty()) {
            throw ValidationException::withMessages([
                'messages' => 'Conversa inválida. Envie ao menos uma mensagem do profissional.',
            ]);
        }

        $latest = $messages->last();
        if ($latest['role'] !== 'user') {
            throw ValidationException::withMessages([
                'messages' => 'A última mensagem precisa ser do profissional.',
            ]);
        }

        $history = $messages->slice(0, -1)->values()->all();
        $context = $this->buildContextPayload($consultation);

        try {
            $reply = $this->aiService->runClinicalChat($context, $latest['content'], $history);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Falha ao gerar resposta do assistente clínico.',
            ], 502);
        }

        return response()->json([
            'reply' => $reply,
            'context' => [
                'patient' => $consultation->patient ? [
                    'id' => (string) $consultation->patient->id,
                    'name' => $consultation->patient->name,
                ] : null,
                'documents' => $consultation->documents->map(function ($doc) {
                    return [
                        'id' => (string) $doc->id,
                        'type' => $doc->type,
                        'title' => $doc->title,
                        'updatedAt' => optional($doc->updated_at)->toIso8601String(),
                    ];
                })->values(),
            ],
        ]);
    }

    private function buildContextPayload(Consultation $consultation): string
    {
        $anamnesis = trim((string) $consultation->anamnesis);
        if ($anamnesis === '') {
            $anamnesis = trim((string) $consultation->transcription);
        }

        $documents = $consultation->documents
            ->map(function ($doc) {
                $header = strtoupper(str_replace('_', ' ', $doc->type));

                return "### {$doc->title} ({$header})\n" . trim((string) $doc->content);
            })
            ->filter(fn ($content) => $content !== '')
            ->values();

        $documentBlock = '';
        if ($documents->isNotEmpty()) {
            $documentBlock = "## Documentos emitidos\n" . $documents->implode("\n\n");
        }

        $payload = trim($anamnesis . "\n\n" . $documentBlock);

        return $payload === '' ? 'Nenhuma anamnese registrada. Utilize somente os documentos listados.' : $payload;
    }
}
