<?php

namespace App\Http\Controllers;

use App\Models\Consultation;
use App\Services\AiAnamnesisService;
use App\Services\AudioProcessorService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TranscriptionController extends Controller
{
    public function __construct(
        private readonly AudioProcessorService $audioProcessor,
        private readonly AiAnamnesisService $aiService
    ) {
    }

    public function transcribe(Request $request)
    {
        $validated = $request->validate([
            'audio' => ['required', 'file'],
            'consultation_id' => ['nullable', 'string'],
            'type' => ['nullable', 'string'],
        ]);

        $type = $validated['type'] ?? 'main';
        $originalPath = $request->file('audio')->store("tmp/uploads/{$type}", 'local');

        $audio = $this->audioProcessor->process($originalPath);

        $segmentPaths = collect($audio['segments'] ?? [])
            ->map(fn ($s) => $s['path'] ?? null)
            ->filter()
            ->values();

        if ($segmentPaths->isEmpty() && isset($audio['source']['path'])) {
            $segmentPaths = collect([$audio['source']['path']]);
        }

        $transcription = $this->aiService->transcribeMany($segmentPaths->all() ?? []);
        if (! $transcription) {
            return response()->json(['message' => 'Falha ao transcrever áudio'], 422);
        }

        $prepared = $this->aiService->prepareTranscriptForAnamnesis($transcription);
        $insights = $this->aiService->buildAnamnesisAndInsights($prepared);

        if (! $insights) {
            return response()->json(['message' => 'Falha ao gerar anamnese/insights'], 422);
        }

        $flags = $this->normalizeFlags($insights['dynamic_flags'] ?? []);
        $questions = $this->normalizeQuestions($insights['missing_questions'] ?? []);
        $summarySource = $insights['summary'] ?? $insights['anamnesis'] ?? $prepared;
        $summary = $this->makeSummary($summarySource);

        $consultationData = null;
        if (! empty($validated['consultation_id'])) {
            $consultation = Consultation::find($validated['consultation_id']);
            if ($consultation) {
                $audioEntry = [
                    'type' => $type,
                    'original' => $originalPath,
                    'optimized' => $audio['source']['path'] ?? null,
                    'segments' => $audio['segments'] ?? [],
                ];

                $audioFiles = $consultation->audio_files ?? [];
                $audioFiles[] = $audioEntry;

                $metadata = $consultation->metadata ?? [];
                $metadata['missingQuestions'] = $questions;
                $metadata['flags'] = $flags;
                $metadata['audioSegments'] = $audio['segments'] ?? [];

                $consultation->fill([
                    'transcription' => $prepared,
                    'anamnesis' => $insights['anamnesis'] ?? null,
                    'summary' => $summary,
                    'status' => 'transcription',
                    'current_step' => 'transcription',
                    'audio_files' => $audioFiles,
                    'metadata' => $metadata,
                ])->save();

                $consultation->load(['patient', 'doctor']);
                $consultationData = app(ConsultationController::class)->transform($consultation);
            }
        }

        return response()->json([
            'audio' => $audio,
            'transcription' => $prepared,
            'anamnesis' => $insights['anamnesis'] ?? null,
            'summary' => $summary,
            'missingQuestions' => $questions,
            'flags' => $flags,
            'consultation' => $consultationData,
        ]);
    }

    private function makeSummary(string $text, int $wordLimit = 50): string
    {
        $plain = trim(strip_tags($text));
        if ($plain === '') {
            return '';
        }
        return Str::words($plain, $wordLimit, '');
    }

    private function normalizeFlags(array $flags): array
    {
        $mapSeverity = [
            'critica' => 'red',
            'alta' => 'orange',
            'moderada' => 'yellow',
            'baixa' => 'gray',
            'red' => 'red',
            'orange' => 'orange',
            'yellow' => 'yellow',
            'gray' => 'gray',
        ];

        $mapType = [
            'clinico' => 'clinical',
            'juridico' => 'legal',
            'cognitivo' => 'clinical',
            'polifarmacia' => 'drug',
        ];

        return collect($flags)->map(function ($flag, $index) use ($mapSeverity, $mapType) {
            return [
                'id' => $flag['id'] ?? 'flag_' . ($index + 1),
                'title' => $flag['title'] ?? 'Alerta',
                'severity' => $mapSeverity[strtolower($flag['severity'] ?? '')] ?? 'yellow',
                'details' => $flag['details'] ?? '',
                'suggestion' => $flag['suggestion'] ?? '',
                'category' => $mapType[strtolower($flag['type'] ?? '')] ?? 'clinical',
            ];
        })->values()->all();
    }

    private function normalizeQuestions(array $questions): array
    {
        return collect($questions)->map(function ($q, $index) {
            if (is_string($q)) {
                return [
                    'id' => 'q_' . ($index + 1),
                    'text' => $q,
                    'category' => 'general',
                    'priority' => 'medium',
                    'isDone' => false,
                ];
            }

            return [
                'id' => $q['id'] ?? 'q_' . ($index + 1),
                'text' => $q['text'] ?? '',
                'category' => $q['category'] ?? 'general',
                'priority' => $q['priority'] ?? 'medium',
                'isDone' => $q['isDone'] ?? false,
            ];
        })->filter(fn ($q) => ! empty($q['text']))->values()->all();
    }
}
