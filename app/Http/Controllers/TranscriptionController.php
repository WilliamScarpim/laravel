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
                $metadata['missingQuestions'] = $insights['missing_questions'] ?? [];
                $metadata['flags'] = $insights['dynamic_flags'] ?? [];
                $metadata['audioSegments'] = $audio['segments'] ?? [];

                $consultation->fill([
                    'transcription' => $prepared,
                    'anamnesis' => $insights['anamnesis'] ?? null,
                    'summary' => $this->makeSummary($insights['anamnesis'] ?? $prepared),
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
            'missingQuestions' => $insights['missing_questions'] ?? [],
            'flags' => $insights['dynamic_flags'] ?? [],
            'consultation' => $consultationData,
        ]);
    }

    private function makeSummary(string $text): string
    {
        $plain = trim(strip_tags($text));
        return Str::limit($plain, 255);
    }
}

